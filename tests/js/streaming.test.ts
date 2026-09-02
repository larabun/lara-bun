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
import { drainQueuedChunks } from '../../resources/runtime.ts'

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
