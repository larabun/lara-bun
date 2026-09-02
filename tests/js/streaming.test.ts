/**
 * The worker must put React's entire shell on the socket before it releases
 * deferred host calls.
 *
 * PHP runs a host callback synchronously on the same thread that pumps the
 * HTML socket, so while one is in flight nothing the worker writes reaches the
 * browser. Releasing the queue after only the first chunk left the rest of the
 * shell — every Suspense fallback in it — stranded for the length of the call:
 * a page with a 2.5s host call painted nothing for 2.5s instead of showing its
 * skeletons immediately.
 */

import { describe, expect, test } from 'bun:test'
import { createDeferredHost, drainQueuedChunks } from '../../resources/streaming.ts'

/** A reader whose chunks are already queued, then goes quiet forever. */
function queuedReader(chunks: string[], thenQuiet = true) {
  let i = 0

  return {
    read(): Promise<{ done: boolean; value?: string }> {
      if (i < chunks.length) return Promise.resolve({ done: false, value: chunks[i++] })
      if (thenQuiet) return new Promise(() => {})

      return Promise.resolve({ done: true })
    },
  }
}

describe('drainQueuedChunks', () => {
  test('writes every queued chunk before returning', async () => {
    const written: string[] = []
    const shell = ['<html>', '<head>', '<body>', '<!--$?-->skeleton']

    const { done } = await drainQueuedChunks(queuedReader(shell), (c) => written.push(c))

    expect(written).toEqual(shell)
    expect(done).toBe(false)
  })

  test('stops at the first read that does not settle, and hands it back', async () => {
    const written: string[] = []
    let resolveLate: ((r: { done: boolean; value?: string }) => void) | null = null
    let reads = 0

    const reader = {
      read(): Promise<{ done: boolean; value?: string }> {
        reads++
        if (reads === 1) return Promise.resolve({ done: false, value: 'shell' })

        return new Promise((resolve) => {
          resolveLate = resolve
        })
      },
    }

    const { pending, done } = await drainQueuedChunks(reader, (c) => written.push(c))

    expect(written).toEqual(['shell'])
    expect(done).toBe(false)
    expect(pending).not.toBeNull()

    // The unsettled read is carried back, not dropped: its chunk still arrives.
    resolveLate!({ done: false, value: 'boundary' })
    expect(await pending!).toEqual({ done: false, value: 'boundary' })
  })

  test('returns immediately when the producer has nothing yet', async () => {
    // A shell that itself awaits a host call cannot produce HTML until that
    // call runs, so the drain must give up at once and let the queue flush.
    const written: string[] = []

    const { pending, done } = await drainQueuedChunks(
      { read: () => new Promise<{ done: boolean; value?: string }>(() => {}) },
      (c) => written.push(c),
    )

    expect(written).toEqual([])
    expect(done).toBe(false)
    expect(pending).not.toBeNull()
  })

  test('reports a stream that ends during the drain', async () => {
    const written: string[] = []

    const { pending, done } = await drainQueuedChunks(queuedReader(['a', 'b'], false), (c) => written.push(c))

    expect(written).toEqual(['a', 'b'])
    expect(done).toBe(true)
    expect(pending).toBeNull()
  })

  test('does not stop early on a chunk that resolves in a later microtask', async () => {
    // Queued chunks settle in microtasks; the race is against a macrotask, so
    // a chunk that is merely a few microtasks deep must still be drained.
    const written: string[] = []
    let i = 0
    const chunks = ['a', 'b', 'c']

    const reader = {
      async read(): Promise<{ done: boolean; value?: string }> {
        await Promise.resolve()
        await Promise.resolve()
        if (i < chunks.length) return { done: false, value: chunks[i++] }

        return new Promise(() => {})
      },
    }

    await drainQueuedChunks(reader, (c) => written.push(c))

    expect(written).toEqual(chunks)
  })
})

describe('createDeferredHost', () => {
  /** Records calls and lets the test settle them by hand. */
  function recordingHost() {
    const calls: string[] = []

    return {
      calls,
      fn: (name: string, ...args: unknown[]): Promise<unknown> => {
        calls.push(name)

        return Promise.resolve(`${name}:${args.join(',')}`)
      },
    }
  }

  test('does not defer before begin(), so metadata is never queued', async () => {
    // generateMetadata runs before any payload exists, so a host call there has
    // nothing to strand. Queueing it only stalled metadata until the backstop.
    const host = recordingHost()
    const deferred = createDeferredHost(host.fn)

    await expect(deferred.hostFn('Meta.title')).resolves.toBe('Meta.title:')
    expect(host.calls).toEqual(['Meta.title'])
  })

  test('queues calls made during the render until flush()', async () => {
    const host = recordingHost()
    const deferred = createDeferredHost(host.fn)
    deferred.begin()

    const pending = deferred.hostFn('Stats.fetch', 7)

    // The whole point: nothing has reached PHP yet, so it cannot be blocking
    // while React still has payload to write.
    expect(host.calls).toEqual([])

    deferred.flush()

    await expect(pending).resolves.toBe('Stats.fetch:7')
    expect(host.calls).toEqual(['Stats.fetch'])
  })

  test('passes calls straight through once flushed', async () => {
    const host = recordingHost()
    const deferred = createDeferredHost(host.fn)
    deferred.begin()
    deferred.flush()

    await expect(deferred.hostFn('Todos.list')).resolves.toBe('Todos.list:')
    expect(host.calls).toEqual(['Todos.list'])
  })

  test('flush() is idempotent and never double-sends a queued call', async () => {
    const host = recordingHost()
    const deferred = createDeferredHost(host.fn)
    deferred.begin()

    const pending = deferred.hostFn('Stats.fetch')
    deferred.flush()
    deferred.flush()

    await pending
    expect(host.calls).toEqual(['Stats.fetch'])
  })

  test('preserves call order across the queue', async () => {
    const host = recordingHost()
    const deferred = createDeferredHost(host.fn)
    deferred.begin()

    const all = Promise.all([deferred.hostFn('a'), deferred.hostFn('b'), deferred.hostFn('c')])
    deferred.flush()

    await all
    expect(host.calls).toEqual(['a', 'b', 'c'])
  })

  test('rejects the caller when the real host call fails', async () => {
    const deferred = createDeferredHost(() => Promise.reject(new Error('socket closed')))
    deferred.begin()

    const pending = deferred.hostFn('Stats.fetch')
    deferred.flush()

    await expect(pending).rejects.toThrow('socket closed')
  })
})

/**
 * The client router has to recognise an intercepted link before it asks the
 * server, so the patterns are baked into the generated browser entry. Nothing
 * called setInterceptManifest after the Vite migration, so the manifest stayed
 * empty and every intercepted route fell through to a full-page navigation —
 * the modal demo silently became a normal page.
 */
describe('intercept manifest wiring', () => {
  test('the generated browser entry passes the manifest to the bootstrap', async () => {
    const source = await Bun.file(new URL('../../resources/vite.ts', import.meta.url)).text()

    // The entry must call createViteRscApp WITH the manifest, not bare.
    expect(source).toContain('createViteRscApp(document, ${JSON.stringify(readInterceptManifest())})')
  })

  test('the bootstrap installs whatever the entry passed it', async () => {
    const source = await Bun.file(new URL('../../resources/js/createViteRscApp.ts', import.meta.url)).text()

    expect(source).toContain('setInterceptManifest(interceptEntries)')
  })
})
