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

describe('ppr classification', () => {
  /**
   * The build asks handleRscPprShell to classify each route. It swaps php()
   * for a probe that never resolves, so anything depending on per-request data
   * stays suspended and only the static shell is flushed. The two flags decide
   * whether a page may be frozen whole, cached as a shell, or left dynamic.
   */
  test('reports a page with no php() as fully static', async () => {
    const r = await engine.handleRscPprShell('app/static/page', {}, LAYOUTS, [], {})

    expect(r.usedDynamicApis).toBe(false)
    expect(r.timedOut).toBe(false)
    // A static page renders to completion, so the shell IS the page.
    expect(r.shellHtml).toContain('Static hello from vite engine')
  })

  test('reports a page that awaits php() as dynamic', async () => {
    const r = await engine.handleRscPprShell('app/page', {}, LAYOUTS, ['app/loading'], {})

    expect(r.usedDynamicApis).toBe(true)
    expect(r.timedOut).toBe(true)
  })

  test('captures the loading.tsx fallback as the shell when the page itself blocks', async () => {
    const r = await engine.handleRscPprShell('app/page', {}, LAYOUTS, ['app/loading'], {})

    // The page never renders, so the shell is the layout plus the boundary.
    expect(r.shellHtml).toContain('<nav>')
    expect(r.shellHtml).not.toContain('Hello ')
  })

  test('captures the page markup as the shell when only a child is dynamic', async () => {
    // This is the case PPR exists for: a real static shell with a hole in it.
    const r = await engine.handleRscPprShell('app/slow2/page', {}, LAYOUTS, [], {})

    expect(r.usedDynamicApis).toBe(true)
    expect(r.shellHtml).toContain('id="slow2-shell"')
    expect(r.shellHtml).not.toContain('arrived after')
  })

  test('leaves the real php() implementation installed afterwards', async () => {
    await engine.handleRscPprShell('app/page', {}, LAYOUTS, ['app/loading'], {})

    // The probe must not leak into subsequent request-time renders.
    const { stream } = await engine.handleRscStream('app/page', { name: 'ramon' }, LAYOUTS, [], {}, {})

    expect(await text(stream)).toContain('ramon')
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

describe('react compiler detection', () => {
  /** Build a fake app root containing only the given node_modules packages. */
  function rootWith(packages: string[]): string {
    const dir = mkdtempSync(join(tmpdir(), 'larabun-compiler-'))

    for (const pkg of packages) {
      mkdirSync(join(dir, 'node_modules', pkg), { recursive: true })
    }

    return dir
  }

  test('enables the compiler only when all three packages are installed', async () => {
    const { detectReactCompiler, REACT_COMPILER_PACKAGES } = await import(
      join(packageRoot, 'resources/build-rsc-vite.ts')
    )
    const dir = rootWith([...REACT_COMPILER_PACKAGES])

    expect(detectReactCompiler(dir).enabled).toBe(true)
    expect(detectReactCompiler(dir).missing).toEqual([])

    rmSync(dir, { recursive: true, force: true })
  })

  test('stays off and names what is missing when only the babel plugin is present', async () => {
    const { detectReactCompiler } = await import(join(packageRoot, 'resources/build-rsc-vite.ts'))
    const dir = rootWith(['babel-plugin-react-compiler'])
    const result = detectReactCompiler(dir)

    // The Babel plugin alone does nothing: it hooks into the react() layer,
    // and rsc() on its own has no such layer.
    expect(result.enabled).toBe(false)
    expect(result.missing).toContain('@vitejs/plugin-react')
    expect(result.missing).toContain('@rolldown/plugin-babel')

    rmSync(dir, { recursive: true, force: true })
  })

  test('stays off for an app with none of them', async () => {
    const { detectReactCompiler, REACT_COMPILER_PACKAGES } = await import(
      join(packageRoot, 'resources/build-rsc-vite.ts')
    )
    const dir = rootWith([])
    const result = detectReactCompiler(dir)

    expect(result.enabled).toBe(false)
    expect(result.missing).toHaveLength(REACT_COMPILER_PACKAGES.length)

    rmSync(dir, { recursive: true, force: true })
  })
})

describe('form data serialization', () => {
  /**
   * buildFormData is the contract between useForm and a server action, and the
   * client half of native file uploads. The hook itself needs a React renderer,
   * but this is where the encoding decisions live.
   */
  async function build(data: Record<string, unknown>) {
    const { buildFormData } = await import(join(packageRoot, 'resources/js/useForm.ts'))

    return buildFormData(data) as FormData
  }

  test('encodes booleans as 1 and 0 so PHP sees something truthy', async () => {
    const fd = await build({ remember: true, subscribed: false })

    expect(fd.get('remember')).toBe('1')
    expect(fd.get('subscribed')).toBe('0')
  })

  test('repeats array values under a bracketed key', async () => {
    const fd = await build({ tags: ['a', 'b'] })

    expect(fd.getAll('tags[]')).toEqual(['a', 'b'])
  })

  test('drops null and undefined rather than sending them as strings', async () => {
    const fd = await build({ name: 'ramon', middle: null, nickname: undefined })

    expect(fd.get('name')).toBe('ramon')
    expect(fd.has('middle')).toBe(false)
    expect(fd.has('nickname')).toBe(false)
  })

  test('passes a File through untouched for native uploads', async () => {
    const file = new File([new Uint8Array([1, 2, 3])], 'avatar.png', { type: 'image/png' })
    const fd = await build({ avatar: file, name: 'ramon' })
    const sent = fd.get('avatar') as File

    // Not stringified — the binary reaches the action intact.
    expect(sent).toBeInstanceOf(File)
    expect(sent.name).toBe('avatar.png')
    expect(sent.type).toBe('image/png')
    expect(await sent.arrayBuffer()).toHaveLength(3)
  })

  test('stringifies numbers and leaves strings alone', async () => {
    const fd = await build({ age: 41, name: 'ramon' })

    expect(fd.get('age')).toBe('41')
    expect(fd.get('name')).toBe('ramon')
  })
})

describe('file uploads through a server action', () => {
  /**
   * The full client→PHP→worker shape for an upload: encodeReply produces
   * FormData, the client serializes it to bytes under an opaque content-type,
   * PHP base64s those bytes over the socket, and the worker hands back a
   * latin1 string that handleAction must turn into FormData again.
   */
  async function latin1MultipartBody(form: FormData) {
    const serialized = new Response(form)
    const contentType = serialized.headers.get('content-type')!
    const bytes = new Uint8Array(await serialized.arrayBuffer())

    // PHP transports raw bytes; the worker decodes base64 to a latin1 string.
    let latin1 = ''
    for (const byte of bytes) latin1 += String.fromCharCode(byte)

    return { body: latin1, contentType }
  }

  test('reconstructs a File from a multipart body', async () => {
    const { encodeReply } = await import('react-server-dom-webpack/client.edge')
    const png = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0xff])
    const file = new File([png], 'avatar.png', { type: 'image/png' })

    const encoded = await encodeReply([file, 'profile picture'])
    expect(encoded).toBeInstanceOf(FormData)

    const { body, contentType } = await latin1MultipartBody(encoded as FormData)
    const { stream } = await engine.handleAction(serverActionId('upload'), body, contentType)
    const payload = await text(stream)

    expect(payload).toContain('avatar.png')
    expect(payload).toContain('image/png')
    expect(payload).toContain('profile picture')
  })

  test('preserves the exact bytes, including non-UTF8 ones', async () => {
    const { encodeReply } = await import('react-server-dom-webpack/client.edge')
    // 0x89 and 0xFF are invalid UTF-8 on their own — a text round-trip mangles
    // them, which is what the latin1 transport exists to prevent.
    const png = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0xff])
    const encoded = await encodeReply([new File([png], 'a.png', { type: 'image/png' }), 'x'])

    const { body, contentType } = await latin1MultipartBody(encoded as FormData)
    const { stream } = await engine.handleAction(serverActionId('upload'), body, contentType)
    const payload = await text(stream)

    expect(payload).toContain('"size":10')
    expect(payload).toContain('[137,80,78,71]')
  })

  test('still handles a plain non-multipart action body', async () => {
    const { stream } = await engine.handleAction(serverActionId('greet'), JSON.stringify(['ramon']))

    expect(await text(stream)).toContain('Hi ramon from a server action')
  })
})
