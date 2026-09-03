// Everything a JavaScript host has to do to serve the RSC engine.
//
// PHP needs ~2,400 lines for this because it cannot run JavaScript: a socket
// bridge, a worker pool, a frame protocol. A JS host imports the engine and
// calls it, and what remains is the part every host would otherwise rewrite —
// matching a url to a route, negotiating how much of the page to send, and
// speaking the header protocol the client expects.
//
// Deliberately not Hono, or Express, or anything: this takes a Request and
// returns a Response, so it runs under Bun.serve, Deno, Workers, Node's
// fetch adapters and any framework built on them. `./hono` is the three-line
// binding for one of them.
//
//   const rsc = createRscHandler({ engine, manifest, assets })
//   Bun.serve({ fetch: (req) => rsc(req).then((r) => r ?? new Response('', { status: 404 })) })

import { matchIntercept, matchRoute, retentionKey, sharedDepth } from './routing.ts'
import { pathKey, patternKey } from './prerender.ts'
import { withRevalidation } from './revalidate.ts'
export { revalidate } from './revalidate.ts'
// Re-exported, not redefined: routing.ts is the one implementation, shared with
// the prerenderer and the generated bundle, and this stays the adapter's
// public surface so a host imports from one place.
export { matchIntercept, matchRoute, sharedDepth } from './routing.ts'
export type { MatchedRoute } from './routing.ts'
import { FLIGHT_TYPE, HEADER, HTML_TYPE } from './headers.ts'
import type { MatchedRoute } from './routing.ts'
import type { RouteManifest } from './manifest.ts'

/** The built server bundle. Only the parts a host calls. */
export interface RscEngine {
  /** The route table this bundle was built from. */
  manifest?(): RouteManifest
  installHostFn(fn: (name: string, ...args: unknown[]) => unknown): void
  handleRscStream(
    component: string,
    props?: Record<string, unknown>,
    layouts?: { component: string; props: Record<string, unknown> }[],
    loadings?: string[],
    parallelSlots?: Record<string, string>,
    slotOverrides?: Record<string, unknown>,
    from?: number,
    pageKey?: string,
  ): Promise<{ stream: ReadableStream; segmentDepth: number }>
  handleRscHtmlStream(
    component: string,
    props?: Record<string, unknown>,
    layouts?: { component: string; props: Record<string, unknown> }[],
    loadings?: string[],
    parallelSlots?: Record<string, string>,
    slotOverrides?: Record<string, unknown>,
  ): Promise<{ htmlStream: ReadableStream }>
  handleRscRevalidate?(target: string, page: unknown): Promise<{ rscPayload: string }>
  handleAction(
    actionId: string,
    body: Uint8Array | string | FormData,
    contentType?: string,
    page?: unknown,
    takeRevalidated?: () => string[],
  ): Promise<{ stream: ReadableStream }>
}

export interface RscHostOptions {
  /** The built server bundle — `import * as engine from './build/rsc/index.js'`. */
  engine: RscEngine
  /**
   * The route table. Defaults to the one the bundle was built with, which is
   * almost always what you want — a manifest passed separately can go stale
   * against the bundle it is describing.
   */
  manifest?: RouteManifest
  /**
   * Functions the app's server components call as `await rpc('name', ...args)`.
   *
   * In the Laravel host this crosses a socket into PHP. Here they are just
   * functions, which is the whole reason a JS host is smaller.
   */
  rpc?: Record<string, (...args: unknown[]) => unknown>
  /**
   * Props for a page, given whatever its url bound.
   *
   * Defaults to the url params alone. A host that loads a user, reads a
   * session or resolves a tenant does it here — this is the one place the
   * engine cannot supply anything for.
   */
  props?: (match: MatchedRoute, request: Request) => Record<string, unknown> | Promise<Record<string, unknown>>
  /**
   * Where the prerenderer wrote its pages, if this build has any.
   *
   * Checked before rendering: a page that was frozen at build time is served
   * from disk, and anything not found there falls through to being rendered
   * now. So a partial prerender is a valid state, not a broken one.
   */
  prerendered?: string
  /** Serve a built browser asset. Return null for anything not found. */
  assets?: (pathname: string, request: Request) => Promise<Response | null> | Response | null
  /**
   * Identifies this build to the client, which compares it on every
   * navigation and falls back to a full load when it changes. Without one a
   * client keeps talking to a deployment that no longer exists — worst behind
   * a CDN, where the shell it holds may already be from an older build.
   */
  version?: string
}

export function createRscHandler(options: RscHostOptions): (request: Request) => Promise<Response | null> {
  const { engine, assets, version } = options
  const manifest = options.manifest ?? engine.manifest?.()

  if (!manifest) {
    throw new Error(
      'No route table. Pass `manifest`, or build with a plugin version that embeds one in the bundle.',
    )
  }

  // Only when this host has functions of its own. Installing unconditionally
  // overwrites whatever was already registered — a prerenderer sharing the
  // same engine instance, or a host that set its own up first — and the
  // symptom is every call failing as unregistered.
  if (options.rpc) installHostFunctions(options.rpc)

  function installHostFunctions(fns: NonNullable<RscHostOptions['rpc']>): void {
    engine.installHostFn(async (name: string, ...args: unknown[]) => {
      const fn = fns[name]

      if (!fn) {
        // Louder than returning null: a typo in a server component otherwise
        // renders as missing data with nothing anywhere saying why.
        throw new Error(
          `No host function named ${JSON.stringify(name)}. Registered: ${Object.keys(fns).join(', ') || '(none)'}`,
        )
      }

      return await fn(...args)
    })
  }

  async function propsFor(match: MatchedRoute, request: Request): Promise<Record<string, unknown>> {
    return options.props ? await options.props(match, request) : match.params
  }

  /** The page a server action was invoked from, so it can re-render regions of it. */
  function pageContext(match: MatchedRoute, props: Record<string, unknown>) {
    return {
      component: match.route.component,
      props,
      layouts: match.route.layouts.map((component) => ({ component, props: {} })),
      loadings: match.route.loadings,
      parallelSlots: match.route.slots,
    }
  }

  function withVersion(headers: Record<string, string>): Record<string, string> {
    return version ? { ...headers, [HEADER.version]: version } : headers
  }

  return async function handle(request: Request): Promise<Response | null> {
    const url = new URL(request.url)

    if (assets) {
      const asset = await assets(url.pathname, request)

      if (asset) return asset
    }

    if (request.method === 'POST' && url.pathname === HEADER.actionPath) {
      return await handleAction(request, url)
    }

    if (request.method !== 'GET' && request.method !== 'HEAD') return null

    if (options.prerendered) {
      const frozen = await servePrerendered(request, url, options.prerendered)

      if (frozen) return frozen
    }

    // One named region of this page, asked for without mutating anything to
    // earn it. What an action invalidated does not come through here — that
    // travels back inside the action's own answer, which is the whole point of
    // marking rather than telling the client to go and ask.
    const revalidating = request.headers.get(HEADER.revalidate)

    if (revalidating !== null && request.headers.get(HEADER.rsc) !== null) {
      return await handleRevalidate(request, url, revalidating)
    }

    // An intercepted navigation renders the page you are already on, with the
    // interceptor dropped into one of its slots — so the modal opens over it
    // and the url changes. Only ever on a client navigation: a hard load has
    // no referer to open over and gets the real page.
    const interceptSlot = request.headers.get(HEADER.intercept)

    if (interceptSlot !== null && request.headers.get(HEADER.rsc) !== null) {
      return await handleIntercept(request, url, interceptSlot)
    }

    const match = matchRoute(manifest, url.pathname)

    if (!match) return null

    const props = await propsFor(match, request)
    const layouts = match.route.layouts.map((component) => ({ component, props: {} }))
    const chain = match.route.layouts

    // A payload request says so with a header on the page's own url, so one
    // route serves both the document and the navigation that follows it.
    if (request.headers.get(HEADER.rsc) === null) {
      const { htmlStream } = await engine.handleRscHtmlStream(
        match.route.component,
        props,
        layouts,
        match.route.loadings,
        match.route.slots,
        {},
      )

      return new Response(htmlStream, {
        headers: withVersion({
          'Content-Type': HTML_TYPE,
          [HEADER.layouts]: chain.join(','),
          Vary: HEADER.rsc,
        }),
      })
    }

    const from = sharedDepth(request.headers.get(HEADER.segments), chain)

    // Proposed by the host, decided by the engine: an interceptor can force a
    // wider render than the client asked for, so what goes back is the depth
    // that came out, never the one that went in.
    const { stream, segmentDepth } = await engine.handleRscStream(
      match.route.component,
      props,
      layouts,
      match.route.loadings,
      match.route.slots,
      {},
      from,
      url.pathname,
    )

    return new Response(stream, {
      headers: withVersion({
        'Content-Type': FLIGHT_TYPE,
        [HEADER.segmentDepth]: String(segmentDepth),
        [HEADER.layouts]: chain.join(','),
        Vary: HEADER.rsc,
      }),
    })
  }

  /**
   * A page rendered at build time, if there is one for this url.
   *
   * The payload has to match the depth the client shares, not simply exist.
   * Serving the whole document to a client that already holds the layouts
   * replaces the root — and replacing the root unmounts everything retained
   * behind it, so going back stops restoring what you had.
   */
  async function servePrerendered(request: Request, url: URL, dir: string): Promise<Response | null> {
    const { readFile } = await import('node:fs/promises')
    const { join } = await import('node:path')
    const key = pathKey(url.pathname)
    const read = async (name: string) => {
      try {
        return await readFile(join(dir, name), 'utf-8')
      } catch {
        return null
      }
    }

    if (request.headers.get(HEADER.rsc) === null) {
      // A frozen page first; then a shell, under this url or under the route's
      // pattern. Nothing in a shell varies by param, so one shell serves every
      // url its route matches — which is the only way a route whose urls were
      // never listed gets anything frozen at all.
      const route = matchRoute(manifest, url.pathname)
      const html =
        (await read(`${key}.html`)) ??
        (await read(`${key}.ppr.html`)) ??
        (route ? await read(`${patternKey(route.route)}.ppr.html`) : null)

      return html === null
        ? null
        : new Response(html, {
            headers: withVersion({ 'Content-Type': HTML_TYPE, Vary: HEADER.rsc }),
          })
    }

    // Only the document is ever served frozen for a shell. The payload is what
    // fills it in, and it has to be rendered now — answering with a frozen one
    // would hand back the same fallbacks the shell already shows, and the page
    // would never finish loading.

    const meta = await read(`${key}.meta.json`)

    // Both or neither: without the chain there is no way to know which depth a
    // payload is for, and guessing means handing the client a segment for a
    // boundary it does not have.
    if (meta === null) return null

    const chain = (JSON.parse(meta).layouts ?? []) as string[]
    const shared = sharedDepth(request.headers.get(HEADER.segments), chain)

    // The variant for exactly this depth, or the whole document. Anything else
    // would be a payload for a boundary the client is not holding.
    const variant = shared > 0 ? await read(`${key}.seg${shared}.flight`) : null
    const payload = variant ?? (await read(`${key}.flight`))

    if (payload === null) return null

    return new Response(payload, {
      headers: withVersion({
        'Content-Type': FLIGHT_TYPE,
        [HEADER.segmentDepth]: String(variant ? shared : 0),
        [HEADER.layouts]: chain.join(','),
        Vary: HEADER.rsc,
      }),
    })
  }

  async function handleRevalidate(request: Request, url: URL, target: string): Promise<Response> {
    if (!engine.handleRscRevalidate) {
      return new Response('This build cannot revalidate', { status: 501 })
    }

    const match = matchRoute(manifest, url.pathname)

    if (!match) return new Response('No such page', { status: 404 })

    const { rscPayload } = await engine.handleRscRevalidate(
      target,
      pageContext(match, await propsFor(match, request)),
    )

    return new Response(rscPayload, {
      headers: withVersion({
        'Content-Type': FLIGHT_TYPE,
        // Echoed so the client can tell which region it is holding.
        [HEADER.revalidate]: target,
        Vary: HEADER.rsc,
      }),
    })
  }

  async function handleIntercept(request: Request, url: URL, slot: string): Promise<Response> {
    const intercept = matchIntercept(manifest, url.pathname, slot)

    if (!intercept) return new Response('No interceptor for this url', { status: 404 })

    const referer = request.headers.get(HEADER.referer)
    const under = referer ? matchRoute(manifest, new URL(referer, url.origin).pathname) : null

    // Without a page to open over there is nothing to intercept: render the
    // interceptor on its own rather than answering with the wrong page.
    const component = under ? under.route.component : intercept.component
    const props = under ? await propsFor(under, request) : intercept.params
    const chain = under ? under.route.layouts : []
    const slots = under ? under.route.slots : {}
    const loadings = under ? under.route.loadings : []

    const overrides = under
      ? { [slot]: { component: intercept.component, props: intercept.params } }
      : {}

    const { stream, segmentDepth } = await engine.handleRscStream(
      component,
      props,
      chain.map((layout) => ({ component: layout, props: {} })),
      loadings,
      slots,
      overrides,
      sharedDepth(request.headers.get(HEADER.segments), chain),
      retentionKey(url.pathname, slot),
    )

    return new Response(stream, {
      headers: withVersion({
        'Content-Type': FLIGHT_TYPE,
        [HEADER.segmentDepth]: String(segmentDepth),
        [HEADER.layouts]: chain.join(','),
        Vary: HEADER.rsc,
      }),
    })
  }

  async function handleAction(request: Request, url: URL): Promise<Response> {
    const actionId = request.headers.get(HEADER.action)

    if (!actionId) return new Response('Missing X-RSC-Action', { status: 400 })

    // The body travels as application/octet-stream so a host that parses
    // multipart cannot consume it first; its real type rides in a header.
    const body = new Uint8Array(await request.arrayBuffer())
    const contentType = request.headers.get(HEADER.contentType) ?? 'text/plain;charset=UTF-8'

    // Where it was invoked from, so anything the action invalidates can be
    // re-rendered against the page that is actually on screen.
    const referer = request.headers.get(HEADER.referer)
    const match = referer ? matchRoute(manifest, new URL(referer, url.origin).pathname) : null
    const page = match ? pageContext(match, await propsFor(match, request)) : undefined

    // Scoped to this action: revalidate() called anywhere inside it, at any
    // depth, marks here and nowhere else — two requests can be in flight and
    // marking is per-request state.
    const { stream } = await withRevalidation((taken) =>
      engine.handleAction(actionId, body, contentType, page, taken),
    )

    return new Response(stream, {
      headers: withVersion({ 'Content-Type': 'text/x-component; charset=utf-8' }),
    })
  }
}

/**
 * Serve built browser assets out of the build's public directory.
 *
 * `dir` is the browser's root, not the asset folder: a request for
 * /assets/x.js reads <dir>/assets/x.js. Stripping the prefix instead reads
 * <dir>/x.js, which is a 404 for every asset and a page that renders and then
 * never hydrates — nothing logs, because the failed request is the browser's.
 *
 * Supplied rather than assumed: in production these belong in front of the
 * application, on whatever already serves static files. This exists so a
 * development server and a single-file binary do not each write it.
 */
export function assetsFrom(dir: string, prefix = '/assets/'): RscHostOptions['assets'] {
  return async (pathname) => {
    if (!pathname.startsWith(prefix)) return null

    // No traversal out of the asset directory, whatever the url claims.
    if (pathname.includes('..')) return null

    const { readFile } = await import('node:fs/promises')
    const { join } = await import('node:path')

    try {
      const bytes = await readFile(join(dir, pathname))

      return new Response(bytes, {
        headers: {
          'Content-Type': contentTypeOf(pathname),
          // Content-hashed by the build, so this is safe and is the difference
          // between a warm navigation and a cold one.
          'Cache-Control': 'public, max-age=31536000, immutable',
        },
      })
    } catch {
      return null
    }
  }
}

function contentTypeOf(pathname: string): string {
  if (pathname.endsWith('.js')) return 'text/javascript; charset=utf-8'
  if (pathname.endsWith('.css')) return 'text/css; charset=utf-8'
  if (pathname.endsWith('.map')) return 'application/json; charset=utf-8'
  if (pathname.endsWith('.svg')) return 'image/svg+xml'
  if (pathname.endsWith('.woff2')) return 'font/woff2'

  return 'application/octet-stream'
}
