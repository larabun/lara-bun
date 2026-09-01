// Engine-level tests for the vite RSC bundle.
//
// The Pest suite covers the PHP layer with BunBridge mocked, so it never
// exercises the thing the worker actually loads. These build the fixture app
// with build-rsc-vite.ts and assert on what the generated entry renders:
// composition (layouts, parallel slots, intercept overrides), Suspense
// streaming, metadata resolution, client references and server actions.
//
// Run with: bun test tests/js
import { afterAll, beforeAll, describe, expect, test } from 'bun:test'
import { dirname, join } from 'node:path'
import { mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'

const packageRoot = join(import.meta.dir, '../..')
const fixtureDir = join(packageRoot, 'tests/fixtures/rsc-app')
const outDir = join(packageRoot, 'bootstrap/rsc/vite-test')
const bundlePath = join(outDir, 'dist/rsc/index.js')

const LAYOUTS = [{ component: 'app/layout', props: {} }]

let engine: any

/** Collect a Flight/HTML stream to a string. */
const text = (s: ReadableStream) => new Response(s).text()

/**
 * Read a stream, recording how long after start each marker first appears.
 * Used to assert that a Suspense fallback reaches the client before the
 * slow data it is standing in for.
 */
async function timeline(stream: ReadableStream, markers: string[]) {
  const start = Date.now()
  const reader = stream.getReader()
  const decoder = new TextDecoder()
  const seen: Record<string, number> = {}
  let buffer = ''

  while (true) {
    const { done, value } = await reader.read()
    if (done) break
    buffer += decoder.decode(value, { stream: true })
    for (const marker of markers) {
      if (!(marker in seen) && buffer.includes(marker)) seen[marker] = Date.now() - start
    }
  }

  return seen
}

beforeAll(async () => {
  const proc = Bun.spawn(
    ['bun', join(packageRoot, 'resources/build-rsc-vite.ts')],
    {
      cwd: packageRoot,
      env: {
        ...process.env,
        NODE_ENV: 'production',
        LARA_BUN_PROJECT_ROOT: packageRoot,
        BUN_RSC_SOURCE_DIR: fixtureDir,
        BUN_RSC_OUT_DIR: outDir,
        BUN_RSC_ASSETS_DIR: join(outDir, 'public'),
      },
      stdout: 'pipe',
      stderr: 'pipe',
    },
  )

  const code = await proc.exited
  if (code !== 0) {
    throw new Error(`fixture build failed (${code}):\n${await new Response(proc.stderr).text()}`)
  }

  // The worker runs the bundle with NODE_ENV=production; React's Flight adds a
  // debug channel outside production, so match the real runtime here.
  process.env.NODE_ENV = 'production'

  engine = await import(bundlePath)
  engine.installPhpFn(async (fn: string, ...args: unknown[]) => {
    if (fn === 'getUser') return { display: 'ramon' }
    if (fn === 'slowData') {
      await new Promise((r) => setTimeout(r, (args[0] as number) ?? 50))
      return { value: `arrived after ${args[0]}ms` }
    }
    return null
  })
}, 120_000)

afterAll(() => {
  rmSync(outDir, { recursive: true, force: true })
})

describe('composition', () => {
  test('renders the page inside its layout', async () => {
    const { stream } = await engine.handleRscStream('app/static/page', {}, LAYOUTS, [], {}, {})
    const payload = await text(stream)

    expect(payload).toContain('Static hello from vite engine')
    expect(payload).toContain('"html"')
  })

  test('passes php() results into the tree', async () => {
    const { stream } = await engine.handleRscStream('app/page', { name: 'ramon' }, LAYOUTS, [], {}, {})

    expect(await text(stream)).toContain('ramon')
  })

  test('encodes client components as client references, not markup', async () => {
    const { stream } = await engine.handleRscStream('app/page', {}, LAYOUTS, [], {}, {})
    const payload = await text(stream)

    // "Counter" travels as a client reference row the browser runtime resolves,
    // not as server-rendered markup.
    expect(payload).toMatch(/:I\[.*"Counter"/)
    expect(payload).not.toContain('<button')
  })

  test('emits stylesheet links inside the payload', async () => {
    const { stream } = await engine.handleRscStream('app/static/page', {}, LAYOUTS, [], {}, {})
    const payload = await text(stream)

    expect(payload).toContain('stylesheet')
    expect(payload).toContain('vite-rsc/importer-resources')
  })
})

describe('parallel routes', () => {
  test('renders the default slot component when nothing intercepts', async () => {
    const { stream } = await engine.handleRscStream(
      'app/feed/page', {}, LAYOUTS, [], { modal: 'app/@modal/default' }, {},
    )
    const payload = await text(stream)

    expect(payload).toContain('Feed content')
    expect(payload).toContain('no modal')
    expect(payload).not.toContain('Modal for photo')
  })
})

describe('route interception', () => {
  test('overrides the slot with the interceptor and its target params', async () => {
    const { stream } = await engine.handleRscStream(
      'app/feed/page', {}, LAYOUTS, [], { modal: 'app/@modal/default' },
      { modal: { component: 'app/@modal/(.)photo/[id]/page', props: { id: '123' } } },
    )
    const payload = await text(stream)

    // The referer page still renders, with the interceptor replacing the slot.
    expect(payload).toContain('Feed content')
    expect(payload).toContain('Modal for photo')
    expect(payload).toContain('123')
    expect(payload).not.toContain('no modal')
  })

  test('renders the full page when the route is not intercepted', async () => {
    const { stream } = await engine.handleRscStream('app/photo/[id]/page', { id: '123' }, LAYOUTS, [], {}, {})
    const payload = await text(stream)

    expect(payload).toContain('Full photo')
    expect(payload).not.toContain('Modal for photo')
  })
})

describe('suspense streaming', () => {
  test('sends the loading.tsx fallback before the slow data resolves', async () => {
    const { stream } = await engine.handleRscStream(
      'app/slow3/page', {}, LAYOUTS, ['app/slow3/loading'], {}, {},
    )
    const seen = await timeline(stream, ['loading…', 'arrived after'])

    expect(seen['loading…']).toBeDefined()
    expect(seen['arrived after']).toBeDefined()
    // The fallback must not wait on the data it stands in for.
    expect(seen['loading…']).toBeLessThan(seen['arrived after'])
  })

  test('streams independent boundaries as each resolves', async () => {
    const { stream } = await engine.handleRscStream('app/slow/page', {}, LAYOUTS, [], {}, {})
    const seen = await timeline(stream, ['Shell rendered', 'arrived after 500ms', 'arrived after 3000ms'])

    expect(seen['Shell rendered']).toBeLessThan(seen['arrived after 500ms'])
    expect(seen['arrived after 500ms']).toBeLessThan(seen['arrived after 3000ms'])
  })

  test('puts <title> in the shell rather than behind the slow boundary', async () => {
    const { htmlStream } = await engine.handleRscHtmlStream('app/slow/page', {}, LAYOUTS, [], {}, {})
    const seen = await timeline(htmlStream, ['<title>', 'arrived after 3000ms'])

    expect(seen['<title>']).toBeDefined()
    expect(seen['<title>']).toBeLessThan(seen['arrived after 3000ms'])
  })
})

describe('metadata', () => {
  test('applies the nearest layout title template to the page title', async () => {
    const md = await engine.resolveMetadata('app/page', {}, LAYOUTS)

    expect(md.title).toBe('Ramon Page · LaraBun')
  })

  test('page metadata overrides layout defaults', async () => {
    const md = await engine.resolveMetadata('app/page', {}, LAYOUTS)

    expect(md.description).toBe('A test page')
  })

  test('falls back to the layout default title when the page has none', async () => {
    const md = await engine.resolveMetadata('app/feed/page', {}, LAYOUTS)

    expect(md.title).toBe('LaraBun Docs')
  })

  test('renders resolved metadata into the document head', async () => {
    const { htmlStream } = await engine.handleRscHtmlStream('app/page', {}, LAYOUTS, [], {}, {})
    const html = await text(htmlStream)

    expect(html).toContain('<title>Ramon Page · LaraBun</title>')
    expect(html).toContain('content="A test page"')
  })
})

describe('server actions', () => {
  /**
   * The plugin keys server references by a content hash, so the id changes
   * whenever actions.ts does. Recover it from the built module rather than
   * pinning a value that any edit to the fixture would invalidate.
   */
  function serverActionId(exportName: string): string {
    const assets = join(outDir, 'dist/rsc/assets')

    for (const file of readdirSync(assets)) {
      const source = readFileSync(join(assets, file), 'utf-8')
      const match = source.match(
        new RegExp(`registerServerReference\\([^,]+,\\s*"([^"]+)",\\s*"${exportName}"\\)`),
      )
      if (match) return `${match[1]}#${exportName}`
    }

    throw new Error(`no registered server action named "${exportName}" in ${assets}`)
  }

  test('runs the action and streams its result', async () => {
    const { stream } = await engine.handleAction(serverActionId('greet'), JSON.stringify(['ramon']))
    const payload = await text(stream)

    expect(payload).toContain('Hi ramon from a server action')
  })
})

describe('loading.tsx validation', () => {
  const LAYOUT = `export default function L({ children }: any) { return <html><body>{children}</body></html> }\n`

  /**
   * Build a throwaway app tree and return the engine's exit code + output.
   * Exit 1 means the build rejected it for a missing loading boundary.
   */
  async function buildApp(files: Record<string, string>) {
    const dir = mkdtempSync(join(tmpdir(), 'larabun-validate-'))
    // The generated entries must sit inside the project so their imports can
    // resolve the project's node_modules; only the app source lives in tmp.
    const buildDir = mkdtempSync(join(packageRoot, 'bootstrap/rsc/validate-'))

    for (const [path, contents] of Object.entries(files)) {
      mkdirSync(dirname(join(dir, path)), { recursive: true })
      writeFileSync(join(dir, path), contents)
    }

    const proc = Bun.spawn(['bun', join(packageRoot, 'resources/build-rsc-vite.ts')], {
      cwd: packageRoot,
      env: {
        ...process.env,
        LARA_BUN_PROJECT_ROOT: packageRoot,
        BUN_RSC_SOURCE_DIR: dir,
        BUN_RSC_OUT_DIR: buildDir,
        BUN_RSC_ASSETS_DIR: join(buildDir, 'public'),
      },
      stdout: 'pipe',
      stderr: 'pipe',
    })

    const [code, stderr] = [await proc.exited, await new Response(proc.stderr).text()]
    rmSync(dir, { recursive: true, force: true })
    rmSync(buildDir, { recursive: true, force: true })

    return { code, stderr }
  }

  test('rejects a page whose own default export awaits php()', async () => {
    const { code, stderr } = await buildApp({
      'app/layout.tsx': LAYOUT,
      'app/blocking/page.tsx':
        `export default async function P() {\n` +
        `  const d: any = await (globalThis as any).php('x')\n` +
        `  return <main>{d}</main>\n` +
        `}\n`,
    })

    expect(code).toBe(1)
    expect(stderr).toContain('app/blocking/page')
    expect(stderr).toContain('awaits php()')
  })

  test('accepts a blocking page that has a loading.tsx in its chain', async () => {
    const { code } = await buildApp({
      'app/layout.tsx': LAYOUT,
      'app/loading.tsx': `export default function L() { return <div>loading</div> }\n`,
      'app/blocking/page.tsx':
        `export default async function P() {\n` +
        `  const d: any = await (globalThis as any).php('x')\n` +
        `  return <main>{d}</main>\n` +
        `}\n`,
    })

    expect(code).toBe(0)
  })

  test('accepts a page whose slow work sits in a child behind its own Suspense', async () => {
    // The page itself is synchronous, so it paints a shell immediately — no
    // loading.tsx required even though the file calls php().
    const { code } = await buildApp({
      'app/layout.tsx': LAYOUT,
      'app/deferred/page.tsx':
        `import { Suspense } from 'react'\n` +
        `async function Slow() {\n` +
        `  const d: any = await (globalThis as any).php('x')\n` +
        `  return <p>{d}</p>\n` +
        `}\n` +
        `export default function P() {\n` +
        `  return <main><Suspense fallback={<i>wait</i>}><Slow /></Suspense></main>\n` +
        `}\n`,
    })

    expect(code).toBe(0)
  })

  test('rejects a page whose route.php resolves props() through a closure', async () => {
    const { code, stderr } = await buildApp({
      'app/layout.tsx': LAYOUT,
      'app/dynamic/page.tsx': `export default function P() { return <main>hi</main> }\n`,
      'app/dynamic/route.php': "<?php\n\nreturn route()->props(fn () => ['a' => 1]);\n",
    })

    expect(code).toBe(1)
    expect(stderr).toContain('props()')
  })

  test('ignores viewData() closures, which never reach React', async () => {
    const { code } = await buildApp({
      'app/layout.tsx': LAYOUT,
      'app/blade/page.tsx': `export default function P() { return <main>hi</main> }\n`,
      'app/blade/route.php': "<?php\n\nreturn route()->viewData(fn () => ['title' => 'x']);\n",
    })

    expect(code).toBe(0)
  })
})
