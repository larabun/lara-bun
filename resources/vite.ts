// File-based routing for React Server Components, as a Vite plugin.
//
// Host-agnostic by design: it discovers an app/ route tree, generates the three
// entries, and exposes a render contract over a global the host installs. What
// that global is called, and how a route declares dynamic props, are options —
// nothing here knows or cares which backend is driving it.
//
//   import { rscRoutes } from '<package>/vite'
//   export default defineConfig({ plugins: [rscRoutes(), react({ compiler: true })] })
//
// The plugin discovers the app/ route tree, generates the three entries that
// carry the route composition and the worker's render contract, and supplies
// the structural config (entries, output dirs, base). @vitejs/plugin-rsc is
// included here so it always runs before any react() layer the app adds.

import { existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import rsc from '@vitejs/plugin-rsc'
import type { Plugin, ResolvedConfig } from 'vite'

export interface RscRoutesOptions {
  /** App root. Defaults to RSC_PROJECT_ROOT, then cwd. */
  projectRoot?: string
  /** Directory holding the app/ route tree. Defaults to resources/js/rsc. */
  sourceDir?: string
  /** Where server bundles are written. Defaults to bootstrap/rsc/vite. */
  outDir?: string
  /** Where the browser bundle is written. Defaults to public/build/rsc-vite. */
  assetsDir?: string
  /** Public URL the browser bundle is served from. */
  assetsUrl?: string
  /**
   * This package's `resources/` directory, holding the client runtime the
   * browser entry imports. Vite stages configs through node_modules/.vite-temp,
   * so import.meta.dir is not this file's real location by the time the plugin
   * runs — the CLI passes the real path through RSC_PACKAGE_DIR.
   */
  packageDir?: string
  /**
   * Name of the global the host installs for calling its own functions from a
   * server component — `await rpc('getUser', id)`. The mechanism is
   * host-agnostic; only the name is a convention, so a host that prefers
   * something else can say so.
   */
  hostGlobal?: string
  /**
   * How to tell that a route's props are resolved dynamically by the host.
   * Laravel writes a `route.php` beside the page and resolves `props()` through
   * a closure; another host substitutes its own file and pattern.
   */
  routeConfig?: { file: string; dynamicPattern: RegExp }
}

// Resolved once per rscRoutes() call. One build runs in one process, so these are
// module state rather than threaded through every helper.
let projectRoot: string
let sourceDir: string
let outDir: string
let appDir: string
let genDir: string
let publicAssetsDir: string
let assetsBaseUrl: string
let packageDir: string
let hostGlobal: string
let routeConfig: { file: string; dynamicPattern: RegExp }

function resolvePaths(options: RscRoutesOptions): void {
  projectRoot = resolve(options.projectRoot || process.env.RSC_PROJECT_ROOT || process.cwd())
  sourceDir = resolve(options.sourceDir || process.env.RSC_SOURCE_DIR || join(projectRoot, 'resources/js/rsc'))
  outDir = resolve(options.outDir || process.env.RSC_OUT_DIR || join(projectRoot, 'bootstrap/rsc/vite'))
  appDir = join(sourceDir, 'app')

  // Generated entries live under the (in-project) out dir so module resolution
  // can walk up to the project's node_modules (@vitejs/plugin-rsc, react, ...).
  genDir = join(outDir, '.gen')

  // The CLIENT bundle is browser-facing and must be web-served from public/; the
  // rsc/ssr bundles are SERVER code and stay under outDir (never public).
  publicAssetsDir = resolve(options.assetsDir || process.env.RSC_ASSETS_DIR || join(projectRoot, 'public/build/rsc-vite'))
  assetsBaseUrl = options.assetsUrl || process.env.RSC_ASSETS_URL || '/build/rsc-vite/'
  packageDir = resolve(options.packageDir || process.env.RSC_PACKAGE_DIR || import.meta.dir)
  hostGlobal = options.hostGlobal || 'rpc'
  routeConfig = options.routeConfig ?? {
    file: 'route.php',
    dynamicPattern: /props\s*\(\s*(fn|function)\s*\(/,
  }
}

interface Component {
  name: string // route-relative key, e.g. "app/page", "app/layout"
  absPath: string
  alias: string // safe JS identifier for the generated import
}

function log(...args: unknown[]): void {
  console.error('[rsc-routes]', ...args)
}

// ── Discovery ────────────────────────────────────────────────────────────────

const ROUTE_FILES = ['page', 'layout', 'loading', 'default']
const EXTS = ['tsx', 'jsx', 'ts', 'js']

function findRouteFile(dir: string, base: string): string | null {
  for (const ext of EXTS) {
    const p = join(dir, `${base}.${ext}`)
    if (existsSync(p)) return p
  }
  return null
}

function componentName(absPath: string): string {
  const rel = relative(sourceDir, absPath).replace(/\\/g, '/')
  return rel.replace(/\.(tsx|jsx|ts|js)$/, '')
}

function toAlias(name: string): string {
  return '_c_' + name.replace(/[^a-zA-Z0-9]/g, '_')
}

const components = new Map<string, Component>()

function register(absPath: string): Component {
  const name = componentName(absPath)
  const existing = components.get(name)
  if (existing) return existing
  const c: Component = { name, absPath, alias: toAlias(name) }
  components.set(name, c)
  return c
}

/** Walk app/ collecting page/layout/loading/default components. */
function discover(dir: string): void {
  for (const base of ROUTE_FILES) {
    const p = findRouteFile(dir, base)
    if (p) register(p)
  }

  for (const entry of readdirSync(dir)) {
    const abs = join(dir, entry)
    if (statSync(abs).isDirectory()) discover(abs)
  }
}

function hasMetadata(absPath: string): boolean {
  const src = readFileSync(absPath, 'utf-8')
  return /export\s+(const\s+metadata|(async\s+)?function\s+generateMetadata)/.test(src)
}

// ── Codegen ──────────────────────────────────────────────────────────────────

function generateEntryRsc(): string {
  const imports: string[] = []
  const mapEntries: string[] = []
  const metaEntries: string[] = []

  for (const c of components.values()) {
    imports.push(`import ${c.alias} from ${JSON.stringify(c.absPath)}`)
    mapEntries.push(`  ${JSON.stringify(c.name)}: ${c.alias},`)

    if (hasMetadata(c.absPath)) {
      imports.push(`import * as ${c.alias}_meta from ${JSON.stringify(c.absPath)}`)
      metaEntries.push(
        `  ${JSON.stringify(c.name)}: { static: ${c.alias}_meta.metadata, generate: ${c.alias}_meta.generateMetadata },`,
      )
    }
  }

  return `// GENERATED by rscRoutes() — do not edit.
import { renderToReadableStream, decodeReply, loadServerAction } from '@vitejs/plugin-rsc/rsc'
import { Suspense, createElement, Fragment } from 'react'
${imports.join('\n')}

type HostFn = (name: string, ...args: unknown[]) => Promise<unknown>
type LayoutEntry = { component: string; props?: Record<string, unknown> }
type SlotOverride = { component: string; props?: Record<string, unknown> }

const components: Record<string, any> = {
${mapEntries.join('\n')}
}

const metadataMap: Record<string, { static?: any; generate?: (p: any) => any }> = {
${metaEntries.join('\n')}
}

// The host installs its callable via installHostFn. The global must be set
// synchronously INSIDE each render fn (applyHost) right before
// renderToReadableStream — setting it once ahead of a separate render call does
// not reach the Flight render.
const HOST_GLOBAL = ${JSON.stringify(hostGlobal)}

let currentHost: HostFn | null = null

export function installHostFn(fn: HostFn) {
  currentHost = fn
  return () => {
    if (currentHost === fn) currentHost = null
  }
}

function applyHost() {
  ;(globalThis as Record<string, unknown>)[HOST_GLOBAL] = currentHost
}

// Composition: layout(outer..inner) > Suspense(loading, innermost-first) > page.
function buildElement(
  component: string,
  props: Record<string, unknown>,
  layouts: LayoutEntry[],
  loadings: string[],
  parallelSlots: Record<string, string>,
  slotOverrides: Record<string, SlotOverride>,
  head: unknown[] = [],
) {
  const Component = components[component]
  if (!Component) throw new Error('Unknown RSC component: ' + component)

  let element = createElement(Component, props)

  for (let i = loadings.length - 1; i >= 0; i--) {
    const Loading = components[loadings[i]]
    element = createElement(Suspense, { fallback: Loading ? createElement(Loading) : null }, element)
  }

  // <title>/<meta> go OUTSIDE the Suspense boundaries so they reach the shell
  // immediately — inside, they would be withheld until the page's data
  // resolves, delaying the whole document on a slow page.
  if (head.length) element = createElement(Fragment, null, ...head, element)

  const slotElements: Record<string, unknown> = {}
  for (const [slot, value] of Object.entries(parallelSlots)) {
    const override = slotOverrides[slot]
    if (override) {
      const OverrideComp = components[override.component]
      slotElements[slot] = OverrideComp ? createElement(OverrideComp, override.props ?? {}) : null
    } else {
      const SlotComp = components[value]
      slotElements[slot] = SlotComp ? createElement(SlotComp, props) : null
    }
  }

  for (let i = layouts.length - 1; i >= 0; i--) {
    const Layout = components[layouts[i].component]
    if (!Layout) continue
    const layoutProps = { ...(layouts[i].props ?? {}) }
    if (i === layouts.length - 1) {
      element = createElement(Layout, { ...layoutProps, ...slotElements, children: element })
    } else {
      element = createElement(Layout, { ...layoutProps, children: element })
    }
  }

  return element
}

// Resolve route metadata into React elements. React 19 hoists <title>/<meta>
// rendered anywhere in the tree into <head> — so the "vite way" for metadata is
// to render it as elements, no PHP-side <head> string injection.
async function renderTree(
  component: string,
  props: Record<string, unknown>,
  layouts: LayoutEntry[],
  loadings: string[],
  parallelSlots: Record<string, string>,
  slotOverrides: Record<string, SlotOverride>,
) {
  const md = await resolveMetadata(component, props, layouts)
  const head: unknown[] = []

  if (md) {
    if (md.title != null) head.push(createElement('title', { key: '__t' }, String(md.title)))
    if (md.description != null) head.push(createElement('meta', { key: '__d', name: 'description', content: String(md.description) }))
    for (const [k, v] of Object.entries(md)) {
      if (k === 'title' || k === 'description' || v == null) continue
      head.push(createElement('meta', { key: '__m_' + k, name: k, content: String(v) }))
    }
  }

  // Metadata elements are rendered INSIDE the document tree so React 19 hoists
  // <title>/<meta> into <head> (hoisting only works from within the tree).
  return buildElement(component, props, layouts, loadings, parallelSlots, slotOverrides, head)
}

// SPA-navigation Flight stream (worker: rsc-stream).
export async function handleRscStream(
  component: string,
  props: Record<string, unknown> = {},
  layouts: LayoutEntry[] = [],
  loadings: string[] = [],
  parallelSlots: Record<string, string> = {},
  slotOverrides: Record<string, SlotOverride> = {},
): Promise<{ stream: ReadableStream; clientChunks: unknown }> {
  applyHost()
  return {
    stream: renderToReadableStream(await renderTree(component, props, layouts, loadings, parallelSlots, slotOverrides)),
    clientChunks: {},
  }
}

// Initial-load HTML stream + hydration payload (worker: rsc-html-stream).
export async function handleRscHtmlStream(
  component: string,
  props: Record<string, unknown> = {},
  layouts: LayoutEntry[] = [],
  loadings: string[] = [],
  parallelSlots: Record<string, string> = {},
  slotOverrides: Record<string, SlotOverride> = {},
  nonce?: string,
): Promise<{ htmlStream: ReadableStream; rscPayloadPromise: Promise<string>; clientChunks: unknown }> {
  applyHost()
  const flight = renderToReadableStream(await renderTree(component, props, layouts, loadings, parallelSlots, slotOverrides))
  const [forHtml, forPayload] = flight.tee()
  const rscPayloadPromise = new Response(forPayload).text()
  const ssr = await (import.meta as any).viteRsc.loadModule('ssr', 'index')
  const htmlStream = await ssr.handleSsr(forHtml, nonce)
  return { htmlStream, rscPayloadPromise, clientChunks: {} }
}

// Server action (worker: rsc-action).
export async function handleAction(
  actionId: string,
  body: string | FormData,
  contentType = 'text/plain',
): Promise<{ stream: ReadableStream }> {
  applyHost()

  let decodable: string | FormData = body

  // A multipart body reaches us as a latin1 string — PHP base64s the raw bytes
  // over the socket and the worker decodes them byte-for-byte. Rebuild the
  // bytes so FormData parses, or any File argument is lost.
  if (typeof body === 'string' && contentType.includes('multipart/form-data')) {
    const bytes = new Uint8Array(body.length)
    for (let i = 0; i < body.length; i++) bytes[i] = body.charCodeAt(i)
    decodable = await new Response(bytes, { headers: { 'Content-Type': contentType } }).formData()
  }

  const args = (await decodeReply(decodable)) as unknown[]
  const action = await loadServerAction(actionId)
  const result = await (action as (...a: unknown[]) => unknown)(...args)
  return { stream: renderToReadableStream(result) }
}

export async function resolveMetadata(
  component: string,
  props: Record<string, unknown> = {},
  layouts: LayoutEntry[] = [],
): Promise<Record<string, unknown> | null> {
  const pageEntry = metadataMap[component]
  const page: Record<string, unknown> = pageEntry
    ? (pageEntry.generate ? ((await pageEntry.generate(props)) ?? {}) : { ...(pageEntry.static ?? {}) })
    : {}

  // Non-title metadata: layout defaults (outer→inner), page overrides.
  const merged: Record<string, unknown> = {}
  for (const l of layouts) {
    const s = metadataMap[l.component]?.static
    if (s) for (const [k, v] of Object.entries(s)) if (k !== 'title') merged[k] = v
  }
  for (const [k, v] of Object.entries(page)) if (k !== 'title') merged[k] = v

  // Title: the page title with the NEAREST layout title.template applied; if the
  // page has no title, the nearest layout default/string title.
  let title: string | undefined = typeof page.title === 'string' ? page.title : undefined
  for (let i = layouts.length - 1; i >= 0; i--) {
    const lt = metadataMap[layouts[i].component]?.static?.title as
      | string | { template?: string; default?: string } | undefined
    if (lt && typeof lt === 'object') {
      if (title != null && lt.template) { title = lt.template.replace('%s', title); break }
      if (title == null && lt.default) { title = lt.default; break }
    } else if (title == null && typeof lt === 'string') { title = lt; break }
  }
  if (title != null) merged.title = title

  return Object.keys(merged).length ? merged : null
}

// Buffered render (worker: rsc / rscWithoutCallbacks — used at prerender time).
export async function handleRsc(
  component: string,
  props: Record<string, unknown> = {},
  _callbackSocket: string | null = null,
  layouts: LayoutEntry[] = [],
  loadings: string[] = [],
  parallelSlots: Record<string, string> = {},
): Promise<{ body: string; rscPayload: string; clientChunks: unknown; usedDynamicApis: boolean }> {
  applyHost()
  // renderTree (not bare buildElement) so the prerendered Flight payload carries
  // the same <title>/<meta> elements the live SPA payload does.
  const flight = renderToReadableStream(await renderTree(component, props, layouts, loadings, parallelSlots, {}))
  const [forHtml, forPayload] = flight.tee()
  const rscPayload = await new Response(forPayload).text()
  const ssr = await (import.meta as any).viteRsc.loadModule('ssr', 'index')
  const htmlStream = await ssr.handleSsr(forHtml)
  const body = await new Response(htmlStream).text()
  return { body, rscPayload, clientChunks: {}, usedDynamicApis: false }
}

// PPR shell + classification (worker: rsc-ppr-shell — build-time).
//
// php() is replaced by a probe that records the call and never resolves, so
// every subtree depending on per-request data stays suspended while everything
// static renders normally. Whatever React has flushed when the deadline passes
// IS the shell: layouts, static markup, and Suspense fallbacks.
//
// The two flags this returns are what the prerender pipeline classifies on:
//   usedDynamicApis — the page touched php(), so it cannot be frozen whole
//   timedOut        — the render never finished, i.e. it is still waiting on
//                     data, so only the shell is safe to cache
// A page that sets neither is genuinely static and can be prerendered fully.
const PPR_SHELL_TIMEOUT_MS = Number(process.env.RSC_PPR_TIMEOUT_MS || 2000)

export async function handleRscPprShell(
  component: string,
  props: Record<string, unknown> = {},
  layouts: LayoutEntry[] = [],
  loadings: string[] = [],
  parallelSlots: Record<string, string> = {},
): Promise<{ shellHtml: string; clientChunks: unknown; timedOut: boolean; usedDynamicApis: boolean; error?: string }> {
  let usedDynamicApis = false
  const realHost = (globalThis as Record<string, unknown>)[HOST_GLOBAL]

  ;(globalThis as Record<string, unknown>)[HOST_GLOBAL] = (..._args: unknown[]) => {
    usedDynamicApis = true
    // Never resolves: the awaiting component suspends and React renders its
    // Suspense fallback into the shell instead of the real content.
    return new Promise(() => {})
  }

  let shellHtml = ''
  let completed = false
  let error: string | undefined
  let cancel: (() => void) | null = null

  const produce = (async () => {
    try {
      const tree = await renderTree(component, props, layouts, loadings, parallelSlots, {})
      const flight = renderToReadableStream(tree)
      const ssr = await (import.meta as any).viteRsc.loadModule('ssr', 'index')
      // Errors here are expected: the render is aborted once the shell is out.
      const htmlStream = await ssr.handleSsr(flight, undefined, () => {})

      const reader = htmlStream.getReader()
      // Cancelling aborts the suspended SSR render, which surfaces React's
      // "render was aborted" both synchronously and as a rejection. Neither is
      // interesting — we already have the shell.
      cancel = () => {
        try {
          const pending = reader.cancel()
          if (pending && typeof pending.catch === 'function') pending.catch(() => {})
        } catch {}
      }
      const decoder = new TextDecoder()

      while (true) {
        const { done, value } = await reader.read()
        if (done) break
        shellHtml += decoder.decode(value, { stream: true })
      }

      completed = true
    } catch (e: any) {
      error = e?.message ?? String(e)
    }
  })()

  await Promise.race([produce, new Promise((r) => setTimeout(r, PPR_SHELL_TIMEOUT_MS))])

  // Release the suspended render; its pending php() promises never settle.
  if (!completed) cancel?.()
  ;(globalThis as Record<string, unknown>)[HOST_GLOBAL] = realHost

  return { shellHtml, clientChunks: {}, timedOut: !completed, usedDynamicApis, error }
}

export default async function handler(): Promise<Response> {
  return new Response('rsc-routes entry', { headers: { 'content-type': 'text/plain' } })
}
`
}

function generateEntrySsr(): string {
  return `// GENERATED by rscRoutes() — do not edit.
import { createFromReadableStream } from '@vitejs/plugin-rsc/ssr'
import { renderToReadableStream } from 'react-dom/server.edge'

export async function handleSsr(
  rscStream: ReadableStream,
  nonce?: string,
  onError?: (error: unknown) => void,
): Promise<ReadableStream> {
  const root = await createFromReadableStream(rscStream)
  const bootstrapScriptContent = await (import.meta as any).viteRsc.loadBootstrapScriptContent('index')
  // Without an onError handler React rejects each abortable task on its own,
  // and those rejections surface as unhandled — noisy for the PPR shell render,
  // which aborts on purpose once it has the shell.
  return renderToReadableStream(root as any, {
    bootstrapScriptContent,
    nonce,
    onError: onError ?? ((error: unknown) => { console.error('[rsc-routes:ssr]', error) }),
  })
}
`
}

function generateEntryBrowser(): string {
  const clientBootstrap = join(packageDir, 'js/createViteRscApp.ts')

  return `// GENERATED by rscRoutes() — do not edit.
import { createViteRscApp } from ${JSON.stringify(clientBootstrap)}

createViteRscApp()
`
}

// ── Validation ───────────────────────────────────────────────────────────────

/**
 * Extract the body of a file's default-exported function.
 *
 * Only the page component's OWN body matters for the loading.tsx rule — sibling
 * components declared in the same file render behind their own boundaries, so
 * their host calls do not block the route's shell.
 */
function defaultExportBody(source: string): string | null {
  const match = source.match(/export\s+default\s+(?:async\s+)?function[^(]*\([^)]*\)\s*{/)
  if (!match) return null

  // Walk from the opening brace to its match, ignoring braces in strings.
  let depth = 0
  const start = match.index! + match[0].length - 1

  for (let i = start; i < source.length; i++) {
    const ch = source[i]
    if (ch === '{') depth++
    else if (ch === '}' && --depth === 0) return source.slice(start, i + 1)
  }

  return null
}

/** Does the page component's own render await the host callable? */
function pageBlocksOnHostCall(source: string): boolean {
  const isAsyncDefault = /export\s+default\s+async\s+function/.test(source)
  if (!isAsyncDefault) return false

  const body = defaultExportBody(source)

  const call = new RegExp(`\\b${hostGlobal}\\s*[<(]|\\bawait\\s+${hostGlobal}\\b`)

  return body !== null && call.test(body)
}

/** Walk up from the page directory to app/ looking for a loading file. */
function hasLoadingInChain(pageDir: string): boolean {
  let dir = pageDir

  while (dir.startsWith(appDir)) {
    if (findRouteFile(dir, 'loading')) return true
    if (dir === appDir) break
    dir = dirname(dir)
  }

  return false
}

/**
 * A route needs loading.tsx only when the PAGE ITSELF blocks — an async default
 * export awaiting the host callable, or the host resolving props dynamically. Both
 * suspend before anything can paint, so without a boundary the user sees a
 * blank screen. A page whose slow work lives in children behind their own
 * <Suspense> already paints a shell and needs nothing.
 */
function validateLoadingBoundaries(): string[] {
  const errors: string[] = []

  for (const c of components.values()) {
    if (!c.name.endsWith('/page') && c.name !== 'app/page') continue

    const pageDir = dirname(c.absPath)
    const source = readFileSync(c.absPath, 'utf-8')

    let reason: string | null = null

    if (pageBlocksOnHostCall(source)) {
      reason = `its default export awaits ${hostGlobal}()`
    } else {
      const configPath = join(pageDir, routeConfig.file)

      if (existsSync(configPath) && routeConfig.dynamicPattern.test(readFileSync(configPath, 'utf-8'))) {
        reason = `${routeConfig.file} resolves props dynamically`
      }
    }

    if (reason && !hasLoadingInChain(pageDir)) {
      errors.push(`  ${c.name} — ${reason}, but has no loading.tsx in its directory chain`)
    }
  }

  return errors
}


// ── Plugin ───────────────────────────────────────────────────────────────────

/** Names of plugins that transform JSX and must run after rsc() has split it. */
const JSX_PLUGIN_PATTERN = /react|babel|oxc/i

export function rscRoutes(options: RscRoutesOptions = {}): Plugin[] {
  resolvePaths(options)

  const routesPlugin: Plugin = {
    name: 'rsc-routes',

    config() {
      if (!existsSync(appDir)) {
        throw new Error(`[rsc-routes] No app directory at ${appDir} — nothing to build.`)
      }

      components.clear()
      discover(appDir)
      log(`Discovered ${components.size} route components:`, [...components.keys()].join(', '))

      const loadingErrors = validateLoadingBoundaries()

      if (loadingErrors.length) {
        throw new Error(
          '[rsc-routes] A page that blocks before it can paint needs a loading.tsx boundary.\n\n' +
            loadingErrors.join('\n') +
            '\n\nAdd loading.tsx in the page directory (or a parent), or move the slow work\n' +
            'into a child component wrapped in its own <Suspense> so the page can paint.',
        )
      }

      if (existsSync(genDir)) rmSync(genDir, { recursive: true, force: true })
      mkdirSync(genDir, { recursive: true })

      writeFileSync(join(genDir, 'entry.rsc.tsx'), generateEntryRsc())
      writeFileSync(join(genDir, 'entry.ssr.tsx'), generateEntrySsr())
      writeFileSync(join(genDir, 'entry.browser.tsx'), generateEntryBrowser())

      return {
        // Public URL for browser-facing client assets (served from public/ by
        // the web server — never through PHP).
        base: assetsBaseUrl,
        root: outDir,
        // Force single instances of React/RSC runtime — critical when the
        // package is symlinked (local dev / monorepo), else "use client"
        // components SSR against a second React copy and hooks throw.
        resolve: { dedupe: ['react', 'react-dom', 'react-server-dom-webpack', '@vitejs/plugin-rsc'] },
        build: { emptyOutDir: true },
        environments: {
          // Server bundles — stay under the (non-public) out dir.
          rsc: { build: { rollupOptions: { input: { index: join(genDir, 'entry.rsc.tsx') } } } },
          ssr: { build: { rollupOptions: { input: { index: join(genDir, 'entry.ssr.tsx') } } } },
          // Client bundle — emitted into public/ for the web server to serve.
          client: {
            build: {
              outDir: publicAssetsDir,
              emptyOutDir: true,
              rollupOptions: { input: { index: join(genDir, 'entry.browser.tsx') } },
            },
          },
        },
      }
    },

    configResolved(config: ResolvedConfig) {
      // rsc() splits the module graph into client and server; a JSX transform
      // placed ahead of it sees the wrong graph and fails in ways that are hard
      // to trace back here. Cheaper to refuse than to let it through.
      const names = config.plugins.map((p) => p.name)
      const rscAt = names.findIndex((n) => n === 'rsc' || n.startsWith('rsc:'))
      const jsxAt = names.findIndex((n) => JSX_PLUGIN_PATTERN.test(n))

      if (rscAt !== -1 && jsxAt !== -1 && jsxAt < rscAt) {
        throw new Error(
          `[rsc-routes] Plugin "${names[jsxAt]}" is resolved ahead of rsc(), so it would ` +
            'transform JSX before the client/server split.\n' +
            'Put rscRoutes() first in your plugins array. If it already is, that plugin ' +
            "sets enforce: 'pre' and needs to be moved after rsc() explicitly.",
        )
      }
    },
  }

  // rsc() ships as several plugins; spreading them keeps rscRoutes() a single
  // entry in the app's array while guaranteeing they lead.
  return [...rsc(), routesPlugin]
}
