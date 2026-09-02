/**
 * The worker, driven over a socket the way PHP drives it.
 *
 * Everything else in this suite calls the engine directly, which skips the
 * transport entirely — the framing, the header/body split, the dispatch. That
 * gap is not theoretical: sending an action body as bytes broke every
 * non-multipart action, and this suite stayed green because nothing here had
 * ever crossed a socket.
 */

import { afterAll, beforeAll, describe, expect, test } from 'bun:test'
import { join } from 'node:path'
import { mkdtempSync, readdirSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { connect } from 'node:net'

const packageRoot = join(import.meta.dir, '../..')
const fixtureDir = join(packageRoot, 'tests/fixtures/rsc-app')
const outDir = join(packageRoot, 'bootstrap/rsc/vite-test')
const bundlePath = join(outDir, 'dist/rsc/index.js')

let worker: ReturnType<typeof Bun.spawn> | null = null
let socketDir = ''
let socketPath = ''

/** One length-prefixed frame: 4-byte big-endian length, then the payload. */
function frame(payload: string | Uint8Array): Uint8Array {
  const body = typeof payload === 'string' ? new TextEncoder().encode(payload) : payload
  const out = new Uint8Array(4 + body.length)
  new DataView(out.buffer).setUint32(0, body.length)
  out.set(body, 4)

  return out
}

/**
 * Send frames and collect the frames that come back, until the socket goes
 * quiet. Payloads are returned as text; every reply in this protocol is JSON.
 */
function exchange(frames: Uint8Array[], quietMs = 900): Promise<string[]> {
  return new Promise((resolve, reject) => {
    const socket = connect(socketPath)
    const replies: string[] = []
    let buffer = Buffer.alloc(0)
    let timer: ReturnType<typeof setTimeout> | null = null

    const done = () => {
      socket.end()
      resolve(replies)
    }

    const idle = () => {
      if (timer) clearTimeout(timer)
      timer = setTimeout(done, quietMs)
    }

    socket.on('connect', () => {
      for (const f of frames) socket.write(f)
      idle()
    })

    socket.on('data', (chunk) => {
      buffer = Buffer.concat([buffer, chunk])

      while (buffer.length >= 4) {
        const length = buffer.readUInt32BE(0)
        if (buffer.length < 4 + length) break

        replies.push(buffer.subarray(4, 4 + length).toString('utf-8'))
        buffer = buffer.subarray(4 + length)
      }

      idle()
    })

    socket.on('error', reject)
  })
}

beforeAll(async () => {
  const build = Bun.spawn(['bun', join(packageRoot, 'resources/build-rsc-vite.ts')], {
    cwd: packageRoot,
    env: {
      ...process.env,
      NODE_ENV: 'production',
      RSC_PROJECT_ROOT: packageRoot,
      RSC_SOURCE_DIR: fixtureDir,
      RSC_OUT_DIR: outDir,
      RSC_ASSETS_DIR: join(outDir, 'public'),
      RSC_VITE_CONFIG: join(packageRoot, 'tests/fixtures/vite.rsc.config.mjs'),
    },
    stdout: 'pipe',
    stderr: 'pipe',
  })

  if ((await build.exited) !== 0) {
    throw new Error(`fixture build failed:\n${await new Response(build.stderr).text()}`)
  }

  socketDir = mkdtempSync(join(tmpdir(), 'rsc-worker-'))
  socketPath = join(socketDir, 'bridge.sock')

  worker = Bun.spawn(['bun', join(packageRoot, 'resources/worker.ts')], {
    cwd: packageRoot,
    env: {
      ...process.env,
      RSC_TRANSPORT: 'unix',
      RSC_SOCKET: socketPath,
      RSC_BUNDLE: bundlePath,
    },
    stdout: 'pipe',
    stderr: 'pipe',
  })

  // Wait for it to be answering rather than guessing at a delay.
  for (let i = 0; i < 100; i++) {
    try {
      const [reply] = await exchange([frame('{"type":"ping"}')], 150)
      if (reply?.includes('pong')) return
    } catch {
      // not listening yet
    }

    await new Promise((r) => setTimeout(r, 100))
  }

  throw new Error('worker never answered a ping')
}, 180_000)

afterAll(() => {
  worker?.kill()
  if (socketDir) rmSync(socketDir, { recursive: true, force: true })
})

/**
 * The server reference id the build assigned to an export.
 *
 * Keyed by a content hash, so it changes whenever the fixture's actions do —
 * recovered from the built module rather than pinned.
 */
function actionId(exportName: string): string {
  const assets = join(outDir, 'dist/rsc/assets')

  for (const file of readdirSync(assets)) {
    const source = readFileSync(join(assets, file), 'utf-8')
    const match = source.match(
      new RegExp(`registerServerReference\\([^,]+,\\s*"([^"]+)",\\s*"${exportName}"\\)`),
    )
    if (match) return `${match[1]}#${exportName}`
  }

  throw new Error(`no registered server action named "${exportName}"`)
}

describe('the frame protocol', () => {
  test('answers a ping', async () => {
    const [reply] = await exchange([frame('{"type":"ping"}')])

    expect(reply).toContain('pong')
  })

  test('an action body arrives as its own frame, and reaches the action', async () => {
    // The exact shape PHP writes: a header declaring the body's length,
    // followed by the body as raw bytes. Dropping the reader that keeps those
    // two together leaves the action with no arguments at all.
    const args = new TextEncoder().encode(JSON.stringify(['ramon']))
    const id = actionId('greet')

    const replies = await exchange([
      frame(
        JSON.stringify({
          type: 'rsc-action',
          actionId: id,
          bodyEncoding: 'binary',
          bodyLength: args.length,
          contentType: 'text/plain;charset=UTF-8',
        }),
      ),
      frame(args),
    ])

    const combined = replies.join('')

    expect(combined).toContain('action-start')
    expect(combined).toContain('Hi ramon from a server action')
  })

  test('a body of bytes that are not valid UTF-8 survives the socket', async () => {
    // json_encode refuses these outright, which is why the body used to be
    // base64'd into the JSON frame. Nothing encodes them now.
    const bytes = new Uint8Array([0x89, 0x50, 0x00, 0xff, 0xfe, 0xc3, 0x28])

    const replies = await exchange([
      frame(
        JSON.stringify({
          type: 'rsc-action',
          actionId: actionId('greet'),
          bodyEncoding: 'binary',
          bodyLength: bytes.length,
          contentType: 'text/plain;charset=UTF-8',
        }),
      ),
      frame(bytes),
    ])

    // The action cannot parse that as arguments; what matters is that the
    // worker stayed on the protocol and answered rather than desynchronising.
    expect(replies.length).toBeGreaterThan(0)
  })
})
