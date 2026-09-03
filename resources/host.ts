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

import { matchIntercept, matchRoute, sharedDepth } from './routing.ts'
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
  const { engine, rpc = {}, assets, version } = options
  const manifest = options.manifest ?? engine.manifest?.()

  if (!manifest) {
    throw new Error(
      'No route table. Pass `manifest`, or build with a plugin version that embeds one in the bundle.',
    )
  }

  // Revalidation targets are per-request: an action marks what it invalidated
  // while it runs, and the answer carries the re-rendered regions back with it
  // instead of the client making a second round trip to ask.
  const marked = new WeakMap<Request, string[]>()

  engine.installHostFn(async (name: string, ...args: unknown[]) => {
    const fn = rpc[name]

    if (!fn) {
      // Louder than returning null: a typo in a server component otherwise
      // renders as missing data with nothing anywhere saying why.
      throw new Error(`No host function named ${JSON.stringify(name)}. Registered: ${Object.keys(rpc).join(', ') || '(none)'}`)
    }

    return await fn(...args)
  })

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
      // Its own retention key: the same url intercepted and not intercepted are
      // two different things to go back to, and one must not restore the other.
      `__intercept:${slot}:${url.pathname}`,
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

    marked.set(request, [])

    const { stream } = await engine.handleAction(actionId, body, contentType, page, () =>
      marked.get(request) ?? [],
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
