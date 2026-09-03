// The JS host adapter: url → route, and the header protocol around it.
//
// Every host would otherwise write this, and each would get the same things
// subtly wrong — matching /docs/new against [slug], answering a navigation
// with a whole document. A fake engine stands in for the bundle, so these run
// without a build and assert on what the adapter decided rather than on
// rendered output.

import { describe, expect, test } from 'bun:test'
import { createRscHandler, matchRoute, sharedDepth } from '../../resources/host.ts'
import { revalidate, withRevalidation } from '../../resources/revalidate.ts'
import type { RouteManifest } from '../../resources/manifest.ts'

const segments = (spec: string) =>
  spec
    .split('/')
    .filter(Boolean)
    .map((part) =>
      part.startsWith('[...')
        ? { type: 'catchAll' as const, value: part.slice(4, -1) }
        : part.startsWith('[')
          ? { type: 'param' as const, value: part.slice(1, -1) }
          : { type: 'static' as const, value: part },
    )

function manifestOf(specs: Record<string, string[]>): RouteManifest {
  return {
    version: 1,
    build: { output: 'server', exportPath: 'dist', payloadName: '' },
    routes: Object.entries(specs).map(([url, layouts]) => ({
      component: `app${url === '/' ? '' : url}/page`,
      segments: segments(url),
      layouts,
      loadings: [],
      slots: {},
      sections: [],
      config: null,
      ancestorConfigs: [],
    })),
    intercepts: [],
  }
}

/**
 * Records what it was asked to render, and answers with an empty stream.
 *
 * `onAction` stands in for the body of a server action. Per engine rather than
 * shared, so two actions can be in flight without one overwriting the other's
 * behaviour — which is the only way to test that their marks stay apart.
 */
function fakeEngine(onAction?: () => void | Promise<void>) {
  const calls: Record<string, unknown[]> = { rsc: [], html: [], action: [], revalidate: [] }
  let hostFn: ((name: string, ...args: unknown[]) => unknown) | null = null

  const empty = () => new ReadableStream({ start: (c) => c.close() })

  return {
    calls,
    callHost: (name: string, ...args: unknown[]) => hostFn!(name, ...args),
    installHostFn(fn: (name: string, ...args: unknown[]) => unknown) {
      hostFn = fn
    },
    async handleRscStream(
      component: string,
      props: unknown,
      layouts: unknown,
      loadings: unknown,
      slots: unknown,
      overrides: unknown,
      from: number,
      pageKey?: string,
    ) {
      calls.rsc.push({ component, props, layouts, slots, overrides, from, pageKey })

      // The engine decides the real depth; here it agrees with the proposal.
      return { stream: empty(), segmentDepth: from }
    },
    async handleRscHtmlStream(component: string, props: unknown) {
      calls.html.push({ component, props })

      return { htmlStream: empty() }
    },
    async handleAction(
      actionId: string,
      body: Uint8Array,
      contentType: string,
      page: unknown,
      takeRevalidated?: () => string[],
    ) {
      // Awaited, so marking and reading are separated by a suspension point —
      // marks that leaked between requests would show up here and nowhere in a
      // synchronous test.
      await onAction?.()
      calls.action.push({
        actionId,
        body: new TextDecoder().decode(body),
        contentType,
        page,
        revalidated: takeRevalidated?.() ?? [],
      })

      return { stream: empty() }
    },
    async handleRscRevalidate(target: string, page: unknown) {
      calls.revalidate.push({ target, page })

      return { rscPayload: `payload for ${target}` }
    },
  }
}

describe('matching a url to a route', () => {
  // The dynamic route is declared first deliberately: taking the first match
  // rather than the most specific one would then answer /docs/new with [slug],
  // and this ordering is what the manifest actually produces — it is sorted by
  // component name, and '[' sorts before 'n'.
  const manifest = manifestOf({
    '/': [],
    '/docs': ['app/layout'],
    '/docs/[slug]': ['app/layout'],
    '/docs/new': ['app/layout'],
    '/files/[...path]': ['app/layout'],
  })

  test('binds a dynamic segment as a param', () => {
    expect(matchRoute(manifest, '/docs/routing')?.params).toEqual({ slug: 'routing' })
  })

  test('prefers the static page over the dynamic one', () => {
    // /docs/new is the page called new, not [slug] with slug="new". Manifest
    // order must not decide this: it is sorted by component name, so the
    // dynamic route can perfectly well come first.
    expect(matchRoute(manifest, '/docs/new')?.route.component).toBe('app/docs/new/page')
  })

  test('a catch-all takes the rest of the path', () => {
    expect(matchRoute(manifest, '/files/a/b/c.txt')?.params).toEqual({ path: 'a/b/c.txt' })
  })

  test('decodes what the url encoded', () => {
    expect(matchRoute(manifest, '/docs/hello%20world')?.params).toEqual({ slug: 'hello world' })
  })

  test('matches the root', () => {
    expect(matchRoute(manifest, '/')?.route.component).toBe('app/page')
  })

  test('does not match a longer path against a shorter route', () => {
    // Without the length check /docs would answer for /docs/a/b as well.
    expect(matchRoute(manifest, '/nope')).toBeNull()
    expect(matchRoute(manifest, '/docs/a/b')).toBeNull()
  })
})

describe('how much of the page to send', () => {
  test('nothing held means a whole document', () => {
    expect(sharedDepth(null, ['app/layout'])).toBe(0)
  })

  test('the shared prefix is what the client can keep', () => {
    expect(sharedDepth('app/layout,app/docs/layout', ['app/layout', 'app/docs/layout'])).toBe(2)
  })

  test('stops at the first difference rather than counting matches', () => {
    expect(sharedDepth('app/layout,app/blog/layout', ['app/layout', 'app/docs/layout'])).toBe(1)
  })

  test('shares nothing with the same layouts in a different order', () => {
    // Depth is a position in the chain, not a set. Asking whether the chain
    // merely contains each held layout says 2 here — and the client would be
    // handed a segment for a boundary it does not have at that depth.
    expect(sharedDepth('app/docs/layout,app/layout', ['app/layout', 'app/docs/layout'])).toBe(0)
  })

  test('never claims more than either chain has', () => {
    expect(sharedDepth('app/layout,app/docs/layout', ['app/layout'])).toBe(1)
  })
})

describe('the request the browser makes', () => {
  const manifest = manifestOf({ '/': [], '/docs/[slug]': ['app/layout'] })

  function handlerFor(engine: ReturnType<typeof fakeEngine>) {
    return createRscHandler({ engine: engine as never, manifest, version: 'build-1' })
  }

  test('a plain request gets the document', async () => {
    const engine = fakeEngine()
    const res = await handlerFor(engine)(new Request('http://x/docs/routing'))

    expect(res?.headers.get('Content-Type')).toStartWith('text/html')
    expect(engine.calls.html).toHaveLength(1)
    expect(engine.calls.rsc).toHaveLength(0)
  })

  test('X-RSC gets a payload on the same url', async () => {
    const engine = fakeEngine()
    const res = await handlerFor(engine)(
      new Request('http://x/docs/routing', { headers: { 'X-RSC': '1' } }),
    )

    expect(res?.headers.get('Content-Type')).toStartWith('text/x-component')
    expect(engine.calls.rsc).toHaveLength(1)
  })

  test('both answers vary on the header that chose between them', async () => {
    // One url, two representations. Without Vary on *both* a cache serves the
    // Flight payload to a browser asking for the page, or the page to a
    // navigation asking for the payload.
    const document = await handlerFor(fakeEngine())(new Request('http://x/'))
    const payload = await handlerFor(fakeEngine())(
      new Request('http://x/', { headers: { 'X-RSC': '1' } }),
    )

    expect(document?.headers.get('Vary')).toBe('X-RSC')
    expect(payload?.headers.get('Vary')).toBe('X-RSC')
  })

  test('the url params reach the page as props', async () => {
    const engine = fakeEngine()
    await handlerFor(engine)(new Request('http://x/docs/routing'))

    expect(engine.calls.html[0]).toMatchObject({ props: { slug: 'routing' } })
  })

  test('the held chain becomes the depth to render from', async () => {
    const engine = fakeEngine()
    const res = await handlerFor(engine)(
      new Request('http://x/docs/routing', {
        headers: { 'X-RSC': '1', 'X-RSC-Segments': 'app/layout' },
      }),
    )

    expect(engine.calls.rsc[0]).toMatchObject({ from: 1 })
    expect(res?.headers.get('X-RSC-Segment-Depth')).toBe('1')
    // What to send back next time.
    expect(res?.headers.get('X-RSC-Layouts')).toBe('app/layout')
  })

  test('the build is named on every answer', async () => {
    // The client compares it and falls back to a full load when it changes;
    // without it a session keeps talking to a deployment that is gone.
    const res = await handlerFor(fakeEngine())(new Request('http://x/'))

    expect(res?.headers.get('X-RSC-Version')).toBe('build-1')
  })

  test('a url the manifest does not claim falls through', async () => {
    // Null, not 404: the host may have its own routes below this one.
    expect(await handlerFor(fakeEngine())(new Request('http://x/health'))).toBeNull()
  })
})

describe('server actions', () => {
  const manifest = manifestOf({ '/': [], '/docs/[slug]': ['app/layout'] })

  test('are taken on the path the client posts to, and nowhere else', async () => {
    // createViteRscApp posts to /_rsc/action unconditionally. Mounted anywhere
    // else the request falls through, the decoder never runs, and the button
    // does nothing — with no error on either side.
    const engine = fakeEngine()
    const handle = createRscHandler({ engine: engine as never, manifest })

    const wrong = await handle(new Request('http://x/action', { method: 'POST' }))
    expect(wrong).toBeNull()

    const right = await handle(
      new Request('http://x/_rsc/action', {
        method: 'POST',
        headers: { 'X-RSC-Action': 'file#greet' },
        body: '["ramon"]',
      }),
    )

    expect(right?.status).toBe(200)
    expect(engine.calls.action[0]).toMatchObject({ actionId: 'file#greet', body: '["ramon"]' })
  })

  test('carry the real content type, which the body does not', async () => {
    // The body goes out as octet-stream so a host that parses multipart cannot
    // consume it first; treating that as the real type leaves an upload
    // undecodable.
    const engine = fakeEngine()

    await createRscHandler({ engine: engine as never, manifest })(
      new Request('http://x/_rsc/action', {
        method: 'POST',
        headers: {
          'X-RSC-Action': 'a#b',
          'X-RSC-Content-Type': 'multipart/form-data; boundary=xyz',
          'Content-Type': 'application/octet-stream',
        },
        body: 'x',
      }),
    )

    expect(engine.calls.action[0]).toMatchObject({ contentType: 'multipart/form-data; boundary=xyz' })
  })

  test('know the page they were invoked from, so they can re-render part of it', async () => {
    const engine = fakeEngine()

    await createRscHandler({ engine: engine as never, manifest })(
      new Request('http://x/_rsc/action', {
        method: 'POST',
        headers: { 'X-RSC-Action': 'a#b', 'X-RSC-Referer': '/docs/routing' },
        body: '[]',
      }),
    )

    expect(engine.calls.action[0]).toMatchObject({
      page: { component: 'app/docs/[slug]/page', props: { slug: 'routing' } },
    })
  })

  test('are refused without naming one', async () => {
    const res = await createRscHandler({ engine: fakeEngine() as never, manifest })(
      new Request('http://x/_rsc/action', { method: 'POST', body: '[]' }),
    )

    expect(res?.status).toBe(400)
  })
})

describe('host functions', () => {
  const manifest = manifestOf({ '/': [] })

  test('are reached by the name a server component calls', async () => {
    const engine = fakeEngine()

    createRscHandler({
      engine: engine as never,
      manifest,
      rpc: { getUser: (id) => ({ id, name: 'ramon' }) },
    })

    expect(await engine.callHost('getUser', 7)).toEqual({ id: 7, name: 'ramon' })
  })

  test('say so when the name is not one of them', async () => {
    // Returning null instead renders as missing data, with nothing anywhere
    // saying the name was wrong.
    const engine = fakeEngine()

    createRscHandler({ engine: engine as never, manifest, rpc: { known: () => 1 } })

    expect(engine.callHost('typo')).rejects.toThrow(/No host function named "typo"/)
  })
})

describe('route interception', () => {
  const manifest: RouteManifest = {
    ...manifestOf({ '/': [], '/feed': ['app/layout'], '/posts/[slug]': ['app/layout'] }),
    intercepts: [
      {
        component: 'app/@modal/(.)posts/[slug]/page',
        slot: 'modal',
        segments: segments('/posts/[slug]'),
        marker: '(.)',
      },
    ],
  }

  // The page the modal opens over declares the slot.
  manifest.routes.find((r) => r.component === 'app/feed/page')!.slots = { modal: 'app/@modal/default' }

  const intercepting = (headers: Record<string, string>) =>
    new Request('http://x/posts/hello', { headers: { 'X-RSC': '1', ...headers } })

  test('renders the page you were on, with the interceptor in its slot', async () => {
    // The modal opens *over* the feed: the component rendered is still the
    // feed's. Rendering the interceptor instead replaces the page behind it,
    // which is a navigation, not an interception.
    const engine = fakeEngine()

    await createRscHandler({ engine: engine as never, manifest })(
      intercepting({ 'X-RSC-Intercept': 'modal', 'X-RSC-Referer': '/feed' }),
    )

    expect(engine.calls.rsc[0]).toMatchObject({
      component: 'app/feed/page',
      overrides: { modal: { component: 'app/@modal/(.)posts/[slug]/page', props: { slug: 'hello' } } },
    })
  })

  test('the interceptor gets the target url params, not the page it opens over', async () => {
    const engine = fakeEngine()

    await createRscHandler({ engine: engine as never, manifest })(
      intercepting({ 'X-RSC-Intercept': 'modal', 'X-RSC-Referer': '/feed' }),
    )

    const call = engine.calls.rsc[0] as { props: unknown; overrides: Record<string, { props: unknown }> }

    expect(call.overrides.modal.props).toEqual({ slug: 'hello' })
    // The feed has no params of its own.
    expect(call.props).toEqual({})
  })

  test('retains under a key of its own', async () => {
    // /posts/hello intercepted and /posts/hello navigated to are two different
    // things to go back to. One key for both and returning to the modal
    // restores the full page, or the other way round.
    const engine = fakeEngine()

    await createRscHandler({ engine: engine as never, manifest })(
      intercepting({ 'X-RSC-Intercept': 'modal', 'X-RSC-Referer': '/feed' }),
    )

    expect(engine.calls.rsc[0]).toMatchObject({ pageKey: '__intercept:modal:/posts/hello' })
  })

  test('falls back to the interceptor alone when there is no page to open over', async () => {
    const engine = fakeEngine()

    await createRscHandler({ engine: engine as never, manifest })(
      intercepting({ 'X-RSC-Intercept': 'modal' }),
    )

    expect(engine.calls.rsc[0]).toMatchObject({
      component: 'app/@modal/(.)posts/[slug]/page',
      props: { slug: 'hello' },
    })
  })

  test('a slot with no interceptor for this url is a 404, not the plain page', async () => {
    const res = await createRscHandler({ engine: fakeEngine() as never, manifest })(
      intercepting({ 'X-RSC-Intercept': 'sidebar', 'X-RSC-Referer': '/feed' }),
    )

    expect(res?.status).toBe(404)
  })

  test('a plain request for the same url gets the real page', async () => {
    // A hard load, or opening the link in a new tab. Nothing to open over, and
    // the header the client sends on an intercepted navigation is absent.
    const engine = fakeEngine()

    await createRscHandler({ engine: engine as never, manifest })(
      new Request('http://x/posts/hello', { headers: { 'X-RSC': '1' } }),
    )

    expect(engine.calls.rsc[0]).toMatchObject({ component: 'app/posts/[slug]/page' })
  })

  test('only ever intercepts a client navigation, never a document load', async () => {
    // The header can arrive on a full page load — a restored tab, a proxy that
    // replays headers. Without the X-RSC check that serves an interception as
    // the whole document: a modal with no page behind it and no way back.
    const engine = fakeEngine()

    await createRscHandler({ engine: engine as never, manifest })(
      new Request('http://x/posts/hello', { headers: { 'X-RSC-Intercept': 'modal', 'X-RSC-Referer': '/feed' } }),
    )

    expect(engine.calls.rsc).toHaveLength(0)
    expect(engine.calls.html[0]).toMatchObject({ component: 'app/posts/[slug]/page' })
  })

  test('the slots a page declares reach the render', async () => {
    // Parallel slots are not interception: the feed renders its @modal/default
    // whether or not anything is intercepting.
    const engine = fakeEngine()

    await createRscHandler({ engine: engine as never, manifest })(
      new Request('http://x/feed', { headers: { 'X-RSC': '1' } }),
    )

    expect(engine.calls.rsc[0]).toMatchObject({ slots: { modal: 'app/@modal/default' } })
  })
})

describe('where the route table comes from', () => {
  test('defaults to the one the bundle carries', async () => {
    // An adapter passes the engine and nothing else. Requiring the manifest
    // separately is the version-skew bug waiting to happen.
    const engine = Object.assign(fakeEngine(), {
      manifest: () => manifestOf({ '/only-in-bundle': [] }),
    })

    const res = await createRscHandler({ engine: engine as never })(
      new Request('http://x/only-in-bundle'),
    )

    expect(res?.status).toBe(200)
  })

  test('an explicit one still wins, for a host that builds its own', async () => {
    const engine = Object.assign(fakeEngine(), {
      manifest: () => manifestOf({ '/from-bundle': [] }),
    })

    const handle = createRscHandler({
      engine: engine as never,
      manifest: manifestOf({ '/from-option': [] }),
    })

    expect(await handle(new Request('http://x/from-option'))).not.toBeNull()
    expect(await handle(new Request('http://x/from-bundle'))).toBeNull()
  })

  test('says so when there is no table at all', async () => {
    // Rather than matching nothing and 404ing every page, which reads as a
    // routing bug in the app.
    expect(() => createRscHandler({ engine: fakeEngine() as never })).toThrow(/No route table/)
  })
})

describe('host functions already installed', () => {
  test('are left alone by a handler that brings none', async () => {
    // A prerenderer sharing this engine instance installs its own, and a host
    // may set one up before building the handler. Installing unconditionally
    // replaces it, and every call then fails as unregistered — which reads as
    // the app calling a name it never registered.
    const engine = fakeEngine()

    engine.installHostFn(async () => 'from the host')

    createRscHandler({ engine: engine as never, manifest: manifestOf({ '/': [] }) })

    expect(await engine.callHost('anything')).toBe('from the host')
  })

  test('are replaced when the handler brings its own', async () => {
    const engine = fakeEngine()

    engine.installHostFn(async () => 'from the host')

    createRscHandler({
      engine: engine as never,
      manifest: manifestOf({ '/': [] }),
      rpc: { greet: () => 'from the handler' },
    })

    expect(await engine.callHost('greet')).toBe('from the handler')
  })
})

describe('revalidating part of a page', () => {
  const manifest = manifestOf({ '/': [], '/orders/[id]': ['app/layout'] })

  test('a request naming a region gets that region alone', async () => {
    const engine = fakeEngine()

    const res = await createRscHandler({ engine: engine as never, manifest })(
      new Request('http://x/orders/7', {
        headers: { 'X-RSC': '1', 'X-RSC-Revalidate': 'orders' },
      }),
    )

    expect(await res!.text()).toBe('payload for orders')
    expect(engine.calls.rsc).toHaveLength(0)
  })

  test('rendered against the page it belongs to', async () => {
    // A section is a region *of a page*, so it needs that page's props — the
    // orders list for order 7, not for whatever page happens to be first.
    const engine = fakeEngine()

    await createRscHandler({ engine: engine as never, manifest })(
      new Request('http://x/orders/7', {
        headers: { 'X-RSC': '1', 'X-RSC-Revalidate': 'orders' },
      }),
    )

    expect(engine.calls.revalidate[0]).toMatchObject({
      target: 'orders',
      page: { component: 'app/orders/[id]/page', props: { id: '7' } },
    })
  })

  test('says which region it is answering with', async () => {
    // The client applies it to a named boundary; an answer that does not say
    // which one would have to be guessed at.
    const res = await createRscHandler({ engine: fakeEngine() as never, manifest })(
      new Request('http://x/', { headers: { 'X-RSC': '1', 'X-RSC-Revalidate': 'orders' } }),
    )

    expect(res?.headers.get('X-RSC-Revalidate')).toBe('orders')
  })

  test('is only ever a payload request, never a document one', async () => {
    // The header can arrive on a navigation. Answering a document request with
    // a bare region replaces the page with a fragment.
    const engine = fakeEngine()

    await createRscHandler({ engine: engine as never, manifest })(
      new Request('http://x/', { headers: { 'X-RSC-Revalidate': 'orders' } }),
    )

    expect(engine.calls.revalidate).toHaveLength(0)
    expect(engine.calls.html).toHaveLength(1)
  })
})

describe('what an action marks while it runs', () => {
  const manifest = manifestOf({ '/': [], '/orders/[id]': ['app/layout'] })

  const invoke = (engine: ReturnType<typeof fakeEngine>) =>
    createRscHandler({ engine: engine as never, manifest })(
      new Request('http://x/_rsc/action', {
        method: 'POST',
        headers: { 'X-RSC-Action': 'a#b', 'X-RSC-Referer': '/orders/7' },
        body: '[]',
      }),
    )

  test('comes back with the action, rather than being fetched afterwards', async () => {
    // One round trip: the answer the user is waiting on carries the
    // re-rendered region with it. Telling the client to go and ask means the
    // result arrives before the screen is right.
    const engine = fakeEngine(() => revalidate('orders'))

    await invoke(engine)

    expect(engine.calls.action[0]).toMatchObject({ revalidated: ['orders'] })
  })

  test('marking nothing marks nothing', async () => {
    const engine = fakeEngine()

    await invoke(engine)

    expect(engine.calls.action[0]).toMatchObject({ revalidated: [] })
  })

  test('the same region marked twice is one region', async () => {
    const engine = fakeEngine(() => {
      revalidate('orders')
      revalidate('orders')
    })

    await invoke(engine)

    expect(engine.calls.action[0]).toMatchObject({ revalidated: ['orders'] })
  })

  test('marking outside an action is ignored rather than fatal', () => {
    // Shared code may mark; being called during an ordinary render must not
    // make it throw.
    expect(() => revalidate('orders')).not.toThrow()
  })

  test('marks belong to the action that made them', async () => {
    // Two actions in flight at once must not see each other's marks. Global
    // state would hand one request the other's regions.
    const first = fakeEngine(async () => {
      revalidate('orders')
      // Yield mid-action, so the other request is running when this one reads.
      await new Promise((r) => setTimeout(r, 5))
    })
    const second = fakeEngine(async () => {
      revalidate('invoices')
      await new Promise((r) => setTimeout(r, 5))
    })

    await Promise.all([invoke(first), invoke(second)])

    expect(first.calls.action[0]).toMatchObject({ revalidated: ['orders'] })
    expect(second.calls.action[0]).toMatchObject({ revalidated: ['invoices'] })
  })
})

describe('marking across module copies', () => {
  test('a second copy of the module marks into the same place', async () => {
    // The app's actions are bundled into the server bundle; the host running
    // them is not. So this module is loaded twice, and a store per copy means
    // the action marks in one and the host reads the other. Nothing errors —
    // the action's answer simply never carries anything back, which reads as
    // revalidation not being implemented.
    const other = (await import('../../resources/revalidate.ts?copy=2')) as typeof import('../../resources/revalidate.ts')

    // Genuinely a different module object, or this proves nothing.
    expect(other.revalidate).not.toBe(revalidate)

    const taken = await withRevalidation(async (take) => {
      other.revalidate('orders')

      return take()
    })

    expect(taken).toEqual(['orders'])
  })
})
