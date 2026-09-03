// The JS host adapter: url → route, and the header protocol around it.
//
// Every host would otherwise write this, and each would get the same things
// subtly wrong — matching /docs/new against [slug], answering a navigation
// with a whole document. A fake engine stands in for the bundle, so these run
// without a build and assert on what the adapter decided rather than on
// rendered output.

import { describe, expect, test } from 'bun:test'
import { createRscHandler, matchRoute, sharedDepth } from '../../resources/host.ts'
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

/** Records what it was asked to render, and answers with an empty stream. */
function fakeEngine() {
  const calls: Record<string, unknown[]> = { rsc: [], html: [], action: [] }
  let hostFn: ((name: string, ...args: unknown[]) => unknown) | null = null

  const empty = () => new ReadableStream({ start: (c) => c.close() })

  return {
    calls,
    callHost: (name: string, ...args: unknown[]) => hostFn!(name, ...args),
    installHostFn(fn: (name: string, ...args: unknown[]) => unknown) {
      hostFn = fn
    },
    async handleRscStream(component: string, props: unknown, layouts: unknown, l: unknown, s: unknown, o: unknown, from: number) {
      calls.rsc.push({ component, props, from })

      // The engine decides the real depth; here it agrees with the proposal.
      return { stream: empty(), segmentDepth: from }
    },
    async handleRscHtmlStream(component: string, props: unknown) {
      calls.html.push({ component, props })

      return { htmlStream: empty() }
    },
    async handleAction(actionId: string, body: Uint8Array, contentType: string, page: unknown) {
      calls.action.push({ actionId, body: new TextDecoder().decode(body), contentType, page })

      return { stream: empty() }
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
