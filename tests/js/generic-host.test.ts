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
