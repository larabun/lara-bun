// Rendering pages at build time, against the real fixture bundle.
//
// The engine renders; the prerenderer decides what to render and what to keep.
// These assert on those decisions — which urls exist, which pages can be
// frozen, and whether what came out can actually be served back at the depth a
// client asks for.

import { afterAll, beforeAll, describe, expect, test } from 'bun:test'
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { join } from 'node:path'
import { tmpdir } from 'node:os'
import { prerender, pathKey, urlFor, urlsToBuild } from '../../resources/prerender.ts'
import { createRscHandler } from '../../resources/host.ts'

const packageRoot = join(import.meta.dir, '../..')
const bundlePath = join(packageRoot, 'bootstrap/rsc/vite-test/dist/rsc/index.js')

let engine: any
let outDir: string
let results: Awaited<ReturnType<typeof prerender>>

beforeAll(async () => {
  // Built here if it is not already there. Sharing engine.test.ts's build is
  // fine when it ran first, but test files must not depend on each other's
  // ordering — running this file alone has to work.
  if (!existsSync(bundlePath)) {
    const proc = Bun.spawn(['bun', join(packageRoot, 'resources/build-rsc-vite.ts')], {
      cwd: packageRoot,
      env: {
        ...process.env,
        NODE_ENV: 'production',
        RSC_PROJECT_ROOT: packageRoot,
        RSC_SOURCE_DIR: join(packageRoot, 'tests/fixtures/rsc-app'),
        RSC_OUT_DIR: join(packageRoot, 'bootstrap/rsc/vite-test'),
        RSC_ASSETS_DIR: join(packageRoot, 'bootstrap/rsc/vite-test/public'),
        RSC_VITE_CONFIG: join(packageRoot, 'tests/fixtures/vite.rsc.config.mjs'),
      },
      stdout: 'pipe',
      stderr: 'pipe',
    })

    if ((await proc.exited) !== 0) {
      throw new Error(`fixture build failed:\n${await new Response(proc.stderr).text()}`)
    }
  }

  engine = await import(bundlePath)
  engine.installHostFn(async () => ({ display: 'ramon' }))

  outDir = mkdtempSync(join(tmpdir(), 'rsc-prerender-'))
  results = await prerender({ engine, outDir, version: 'build-1' })
  // Every fixture route gets a shell probe, and the slow ones are slow on
  // purpose — the budget has to cover the build plus all of them.
}, 180_000)

afterAll(() => {
  rmSync(outDir, { recursive: true, force: true })
})

const wrote = (name: string) => existsSync(join(outDir, name))
const resultFor = (url: string) => results.find((r) => r.url === url)

describe('which urls exist', () => {
  test('a route with no params is one url', () => {
    expect(urlFor({ segments: [{ type: 'static', value: 'feed' }] } as never, {})).toBe('/feed')
  })

  test('a parameterised route takes its values from the params', () => {
    expect(
      urlFor(
        {
          segments: [
            { type: 'static', value: 'photo' },
            { type: 'param', value: 'id' },
          ],
        } as never,
        { id: '7' },
      ),
    ).toBe('/photo/7')
  })

  test('the root is stored as index', () => {
    // '' is not a filename.
    expect(pathKey('/')).toBe('index')
    expect(pathKey('/photo/7')).toBe('photo/7')
  })

  test('a route that declares its urls contributes one entry each', async () => {
    const entries = await urlsToBuild(engine.manifest(), engine)
    const photos = entries.filter((e) => e.route.component === 'app/photo/[id]/page')

    expect(photos.map((e) => e.url).sort()).toEqual(['/photo/1', '/photo/2'])
  })

  test('a parameterised route that declares none contributes nothing', async () => {
    // Not an error: rendering on demand is a legitimate answer, and the
    // alternative is guessing at slugs the app never listed.
    const manifest = engine.manifest()
    const entries = await urlsToBuild(manifest, {
      ...engine,
      getStaticParams: async () => null,
    } as never)

    expect(entries.some((e) => e.route.component === 'app/photo/[id]/page')).toBe(false)
  })

  test('a route the manifest says declares none is not even asked', async () => {
    // The flag exists so a host can plan a build without running app code.
    // Reaching for the export anyway makes it decorative — and the fixture has
    // no undeclared parameterised route to notice with, so this states the
    // table rather than borrowing one.
    const asked: string[] = []
    const route = (component: string, staticParams: boolean) => ({
      component,
      segments: [
        { type: 'static' as const, value: component.split('/')[1] },
        { type: 'param' as const, value: 'id' },
      ],
      layouts: [],
      loadings: [],
      slots: {},
      sections: [],
      config: null,
      ancestorConfigs: [],
      staticParams,
    })

    await urlsToBuild(
      {
        version: 1,
        build: { output: 'server', exportPath: 'dist', payloadName: '' },
        routes: [route('app/declared/[id]/page', true), route('app/undeclared/[id]/page', false)],
        intercepts: [],
      },
      {
        getStaticParams: async (component: string) => {
          asked.push(component)

          return [{ id: '1' }]
        },
      } as never,
    )

    expect(asked).toEqual(['app/declared/[id]/page'])
  })

  test('interceptors are never urls of their own', async () => {
    const entries = await urlsToBuild(engine.manifest(), engine)

    expect(entries.some((e) => e.route.component.includes('(.'))).toBe(false)
  })
})

describe('which pages can be frozen', () => {
  test('a page that renders without reaching for anything is written', () => {
    expect(resultFor('/static')?.type).toBe('static')
    expect(wrote('static.html')).toBe(true)
    expect(wrote('static.flight')).toBe(true)
  })

  test('a page that reaches for the host is left to render on demand', () => {
    // The probe replaces the host global with a promise that never resolves,
    // so the page says what it is by reaching. Freezing it would bake one
    // request's data into every response.
    expect(resultFor('/')?.type).toBe('dynamic')
    expect(wrote('index.html')).toBe(false)
  })

  test('so is one whose slow work sits behind Suspense', () => {
    // It still reaches the host, just not before it can paint. Shipping the
    // shell and streaming the rest is PPR, which is a later slice; until then
    // it renders on demand.
    expect(resultFor('/slow')?.type).toBe('dynamic')
  })

  test('and names the host call rather than the timeout it also caused', () => {
    // A page that reaches for the host also fails to finish: the probe hands
    // it a promise that never resolves. Reporting the timeout would describe
    // every such page as merely slow and point at the wrong fix.
    expect(resultFor('/')?.reason).toMatch(/host/)
    expect(resultFor('/slow')?.reason).toMatch(/host/)
  })
})

describe('what gets written', () => {
  test('a variant for every depth a client might already hold', () => {
    // Without them every navigation to a prerendered route is a whole
    // document, which replaces the root and unmounts the pages retained
    // behind it — so going back does not restore the form you were filling in.
    expect(wrote('static.seg1.flight')).toBe(true)
  })

  test('the layout chain, so a host knows what the variants are for', () => {
    const meta = JSON.parse(readFileSync(join(outDir, 'static.meta.json'), 'utf-8'))

    expect(meta.layouts).toEqual(['app/layout'])
    expect(meta.version).toBe('build-1')
  })

  test('the page a layout declares a slot for is rendered into it', () => {
    // A frozen page whose layout declares a parallel slot comes out whole
    // apart from that region, and nothing says so — the file is written, the
    // build succeeds, and the modal is simply absent for ever.
    expect(readFileSync(join(outDir, 'static.html'), 'utf-8')).toContain('modal-default')
  })

  test('the document carries the bootstrap that makes it interactive', () => {
    const html = readFileSync(join(outDir, 'static.html'), 'utf-8')

    expect(html).toContain('<html')
    expect(html).toMatch(/<script/)
  })
})

describe('serving what was written', () => {
  const handler = () =>
    createRscHandler({ engine, prerendered: outDir, version: 'build-1' })

  test('a plain request gets the frozen document', async () => {
    const res = await handler()(new Request('http://x/static'))

    expect(res?.status).toBe(200)
    expect(await res!.text()).toContain('<html')
  })

  test('a client holding the layout gets the segment, not the document', async () => {
    const res = await handler()(
      new Request('http://x/static', {
        headers: { 'X-RSC': '1', 'X-RSC-Segments': 'app/layout' },
      }),
    )

    expect(res?.headers.get('X-RSC-Segment-Depth')).toBe('1')
  })

  test('a client holding nothing gets the whole document payload', async () => {
    const res = await handler()(new Request('http://x/static', { headers: { 'X-RSC': '1' } }))

    expect(res?.headers.get('X-RSC-Segment-Depth')).toBe('0')
  })

  test('a client claiming a chain this route does not have gets the document', async () => {
    // Its layouts differ, so nothing is shared and no variant applies.
    const res = await handler()(
      new Request('http://x/static', {
        headers: { 'X-RSC': '1', 'X-RSC-Segments': 'app/other/layout' },
      }),
    )

    expect(res?.headers.get('X-RSC-Segment-Depth')).toBe('0')
  })

  test('a url that was not frozen still renders', async () => {
    // A partial prerender is a valid state, not a broken one.
    const res = await handler()(new Request('http://x/feed'))

    expect(res?.status).toBe(200)
    expect(res?.headers.get('Content-Type')).toStartWith('text/html')
  })
})
