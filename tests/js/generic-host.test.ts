/**
 * The plugin is published on its own, so it must assume no particular backend.
 *
 * Laravel's conventions — the `route.php` marker, the `laravel-rsc` import
 * prefix, `resources/js/rsc`, `bootstrap/rsc` — are supplied by the Laravel
 * package at build time. None may be a default here, or the plugin quietly
 * only fits one host.
 */

import { describe, expect, test } from 'bun:test'
import { join } from 'node:path'
import { mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs'

const packageRoot = join(import.meta.dir, '../..')

/** Run the plugin's config hook and return what it contributed. */
async function configFor(options: Record<string, unknown>): Promise<any> {
  const { rscRoutes } = await import('../../resources/vite.ts')
  const plugins = rscRoutes(options as never) as any[]
  const routes = plugins.find((p) => p.name === 'rsc-routes')

  return routes.config({}, { command: 'build', mode: 'production' })
}

describe('the plugin source', () => {
  const source = readFileSync(join(packageRoot, 'resources/vite.ts'), 'utf-8')

  test('names no backend-specific file convention', () => {
    // route.php is Laravel's marker for dynamic props; the plugin takes the
    // filename and pattern from its host instead of knowing either.
    expect(source).not.toContain("'route.php'")
    expect(source).not.toContain('route.rb')
  })

  test('defaults no import prefix to a particular package', () => {
    expect(source).not.toContain("packageAlias || 'laravel-rsc'")
    expect(source).not.toContain("options.packageAlias || 'laravel-rsc'")
  })

  test('defaults no path to a backend layout', () => {
    for (const laravelism of ['resources/js/rsc', 'bootstrap/rsc', 'public/build/rsc-vite']) {
      expect(source).not.toContain(`'${laravelism}'`)
    }
  })
})

describe('a host that passes nothing', () => {
  test('builds from src/app into dist/client and .rsc', () => {
    // Inside the package so the fixture resolves react/vite from node_modules,
    // the way a real project resolves its own.
    const app = mkdtempSync(join(packageRoot, 'bootstrap/rsc/generic-'))

    mkdirSync(join(app, 'src/app'), { recursive: true })
    writeFileSync(
      join(app, 'src/app/layout.tsx'),
      'export default function L({ children }: any) { return <html><body>{children}</body></html> }\n',
    )
    writeFileSync(join(app, 'src/app/page.tsx'), 'export default function P() { return <main>hi</main> }\n')
    writeFileSync(join(app, 'package.json'), '{"name":"generic-app"}\n')
    writeFileSync(
      join(app, 'vite.config.mjs'),
      `import { rscRoutes } from ${JSON.stringify(join(packageRoot, 'resources/vite.ts'))}\n` +
        'export default { plugins: [rscRoutes()] }\n',
    )

    const proc = Bun.spawnSync(['bun', join(packageRoot, 'resources/build-rsc-vite.ts')], {
      cwd: packageRoot,
      env: {
        ...process.env,
        RSC_PROJECT_ROOT: app,
        RSC_VITE_CONFIG: join(app, 'vite.config.mjs'),
        RSC_PACKAGE_DIR: join(packageRoot, 'resources'),
        // Deliberately no RSC_SOURCE_DIR / RSC_OUT_DIR / RSC_ASSETS_DIR:
        // the plugin's own defaults are what is under test.
        RSC_SOURCE_DIR: '',
        RSC_OUT_DIR: '',
        RSC_ASSETS_DIR: '',
        RSC_ASSETS_URL: '',
        RSC_PACKAGE_ALIAS: '',
        RSC_ROUTE_CONFIG_FILE: '',
        RSC_ROUTE_CONFIG_PATTERN: '',
      },
    })

    expect(proc.exitCode).toBe(0)
    expect(readdirSync(join(app, 'dist/client/assets')).some((f) => f.endsWith('.js'))).toBe(true)
    expect(readdirSync(join(app, '.rsc/dist'))).toContain('rsc')

    rmSync(app, { recursive: true, force: true })
  }, 180_000)
})

describe('the package alias', () => {
  test('is not applied when the package is installed', async () => {
    // An alias is a path rewrite and rewrites nothing through the package's
    // own exports, so with both in play `<pkg>/Form` meant one thing to the
    // bundler and another to the exports map. Installed, ordinary resolution
    // has to win.
    const root = mkdtempSync(join(packageRoot, 'bootstrap/rsc/alias-'))
    mkdirSync(join(root, 'node_modules', 'rsc-router'), { recursive: true })
    mkdirSync(join(root, 'src', 'app'), { recursive: true })
    writeFileSync(join(root, 'src', 'app', 'page.tsx'), 'export default function P() { return null }')

    const config = await configFor({ projectRoot: root, packageAlias: 'rsc-router' })

    expect(config.resolve?.alias ?? []).toEqual([])

    rmSync(root, { recursive: true, force: true })
  })

  test('is applied when it is not', async () => {
    const root = mkdtempSync(join(packageRoot, 'bootstrap/rsc/alias-'))
    mkdirSync(join(root, 'src', 'app'), { recursive: true })
    writeFileSync(join(root, 'src', 'app', 'page.tsx'), 'export default function P() { return null }')

    const config = await configFor({ projectRoot: root, packageAlias: 'rsc-router' })

    expect(config.resolve?.alias ?? []).toHaveLength(1)

    rmSync(root, { recursive: true, force: true })
  })
})

describe('what the build produces', () => {
  test('a server build leaves the header doing the work', async () => {
    const root = mkdtempSync(join(packageRoot, 'bootstrap/rsc/output-'))
    mkdirSync(join(root, 'src', 'app'), { recursive: true })
    writeFileSync(join(root, 'src', 'app', 'page.tsx'), 'export default function P() { return null }')

    const config = await configFor({ projectRoot: root })

    expect(config.build?.rollupOptions).toBeUndefined()
    rmSync(root, { recursive: true, force: true })
  })

  test('an export build decides for itself that payloads need urls', async () => {
    // There is no server to read a header on a static host, so the client has
    // to be built asking for a file. The build knows that; nothing has to tell
    // it, and nothing else has to agree with it.
    const root = mkdtempSync(join(packageRoot, 'bootstrap/rsc/output-'))
    mkdirSync(join(root, 'src', 'app'), { recursive: true })
    writeFileSync(join(root, 'src', 'app', 'page.tsx'), 'export default function P() { return null }')

    await configFor({ projectRoot: root, output: 'export', exportPath: 'out' })

    const manifest = JSON.parse(readFileSync(join(root, '.rsc', 'routes.json'), 'utf-8'))

    expect(manifest.build).toEqual({
      output: 'export',
      exportPath: 'out',
      payloadName: 'index.rsc',
    })

    rmSync(root, { recursive: true, force: true })
  })

  test('a server build says so, and asks for no payload filename', async () => {
    const root = mkdtempSync(join(packageRoot, 'bootstrap/rsc/output-'))
    mkdirSync(join(root, 'src', 'app'), { recursive: true })
    writeFileSync(join(root, 'src', 'app', 'page.tsx'), 'export default function P() { return null }')

    await configFor({ projectRoot: root })

    const manifest = JSON.parse(readFileSync(join(root, '.rsc', 'routes.json'), 'utf-8'))

    expect(manifest.build.output).toBe('server')
    expect(manifest.build.payloadName).toBe('')

    rmSync(root, { recursive: true, force: true })
  })
})
