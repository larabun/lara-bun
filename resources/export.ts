// Writing the whole site out as files, for a host that only serves files.
//
// Everything a server was doing has to have happened already, which makes the
// refusals the important part. A site exported with a route missing, or with a
// shell nothing will ever fill, does not fail at build time and does not fail
// on load — it serves a page that sits there, or a 404 for a url that worked
// yesterday. So anything that is not fully static stops the export and says
// which routes and why.
//
// Layout is a directory per route with an index inside it, so urls stay
// extensionless, and the Flight payload sits beside it under a filename the
// client was built to ask for.
//
//   out/index.html            /
//   out/index.rsc
//   out/docs/index.html       /docs
//   out/docs/index.rsc
//   out/assets/…              the browser bundle

import { cp, mkdir, readFile, writeFile } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { pathKey } from './prerender.ts'
import type { PrerenderResult } from './prerender.ts'
import type { RouteManifest } from './manifest.ts'

export interface ExportOptions {
  /** What prerender() decided, so this can refuse what it cannot serve. */
  results: PrerenderResult[]
  /** Where prerender() wrote. */
  from: string
  /** Where the site goes. */
  to: string
  /** The route table, for what the build decided about payload urls. */
  manifest: RouteManifest
  /** The browser bundle, and the url it is served from. */
  assets?: { dir: string; url?: string }
  /**
   * Export anyway, leaving out whatever is not static.
   *
   * The result is a site with holes in it, which is sometimes what you want
   * while moving an app towards being exportable. It is never the default,
   * because the holes are 404s at urls that worked before.
   */
  force?: boolean
}

export class NotExportable extends Error {
  constructor(public readonly refused: PrerenderResult[]) {
    super(
      'This site cannot be exported as it is. A static host runs nothing, so these routes have ' +
        'no way to finish rendering:\n\n' +
        refused.map((r) => `  ${r.url} — ${r.reason ?? describe(r.type)}`).join('\n') +
        '\n\nMake them static, or pass force to export the rest and leave these out.',
    )
    this.name = 'NotExportable'
  }
}

function describe(type: PrerenderResult['type']): string {
  if (type === 'ppr') {
    // Worth spelling out: a shell looks like a working page in the build
    // output, and on a static host it is a page that loads and stays empty.
    return 'only a shell was rendered, and nothing on a static host will fill it'
  }

  return type === 'dynamic' ? 'renders on demand' : 'failed to render'
}

export async function exportSite(options: ExportOptions): Promise<{ pages: number; refused: PrerenderResult[] }> {
  const { results, from, to, manifest, assets, force = false } = options
  const refused = results.filter((r) => r.type !== 'static')

  if (refused.length > 0 && !force) throw new NotExportable(refused)

  // The client has to ask for payloads by url, because a static host cannot
  // read the header that would otherwise select one. That is decided by the
  // build, so a site exported from a server build ships a client asking the
  // wrong way — every navigation silently falls back to a full page load.
  const payloadName = manifest.build?.payloadName

  if (!payloadName) {
    throw new Error(
      'This build was made for a server, so its client asks for payloads with a header rather ' +
        "than by url. Build with output: 'export' before exporting.",
    )
  }

  let pages = 0

  for (const result of results) {
    if (result.type !== 'static') continue

    const key = pathKey(result.url)
    // The root is the out dir itself; everything else is a directory with an
    // index, so /docs stays /docs rather than becoming /docs.html.
    const dir = key === 'index' ? to : join(to, key)

    await mkdir(dir, { recursive: true })
    await copy(join(from, `${key}.html`), join(dir, 'index.html'))
    await copy(join(from, `${key}.flight`), join(dir, payloadName))

    // One file per depth a client might already hold, addressed by name
    // because a file server cannot vary on a header. Without these every
    // navigation is a whole document, which replaces the root and unmounts
    // the pages retained behind it — so going back does not restore the form
    // you were filling in.
    for (let depth = 1; ; depth++) {
      const variant = join(from, `${key}.seg${depth}.flight`)

      if (!(await exists(variant))) break

      await copy(variant, join(dir, payloadName.replace(/^index\./, `index.seg${depth}.`)))
    }

    pages++
  }

  if (assets?.dir) {
    const url = (assets.url ?? '/assets/').replace(/^\/+|\/+$/g, '')

    if (url !== '') {
      const target = join(to, url)

      await mkdir(dirname(target), { recursive: true })
      await cp(assets.dir, target, { recursive: true })
    }
  }

  return { pages, refused }
}

async function exists(path: string): Promise<boolean> {
  try {
    await readFile(path)

    return true
  } catch {
    return false
  }
}

async function copy(from: string, to: string): Promise<void> {
  await writeFile(to, await readFile(from))
}
