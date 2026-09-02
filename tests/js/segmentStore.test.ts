/**
 * What each boundary is showing, and what it keeps alive behind it.
 *
 * Its default matters as much as its behaviour: with nothing stored, a
 * boundary renders the children the server sent — the behaviour that existed
 * before boundaries did.
 */

import { afterEach, describe, expect, test } from 'bun:test'
import {
  RETENTION,
  clearSegments,
  getSegmentState,
  restoreSegments,
  seedSegment,
  setSegment,
  subscribeToSegment,
} from '../../resources/js/segmentStore'

afterEach(() => clearSegments())

describe('defaults', () => {
  test('an untouched depth has no state, meaning "render the server tree"', () => {
    expect(getSegmentState(1)).toBeNull()
  })
})

describe('replacing a segment', () => {
  test('shows the new page and keeps the old one mounted', () => {
    setSegment(2, '/a', 'tree-a')
    setSegment(2, '/b', 'tree-b')

    const state = getSegmentState(2)!

    expect(state.activeKey).toBe('/b')
    // /a is still there — hidden, not unmounted, so its state survives.
    expect(state.entries.map((e) => e.key)).toEqual(['/a', '/b'])
  })

  test('discards deeper segments, which belonged to the replaced page', () => {
    setSegment(1, '/docs', 'section')
    setSegment(2, '/docs/a', 'page')

    setSegment(1, '/blog', 'other-section')

    expect(getSegmentState(2)).toBeNull()
  })

  test('notifies the deeper boundary it was discarded', () => {
    setSegment(2, '/docs/a', 'page')
    let notified = 0
    subscribeToSegment(2, () => notified++)

    setSegment(1, '/blog', 'other')

    expect(notified).toBe(1)
  })
})

describe('returning to a page', () => {
  test('reveals it without a new tree', () => {
    setSegment(2, '/a', 'tree-a')
    setSegment(2, '/b', 'tree-b')

    expect(restoreSegments('/a')).toBe(true)
    expect(getSegmentState(2)!.activeKey).toBe('/a')
  })

  test('refuses a page no boundary is holding, so the router fetches', () => {
    setSegment(2, '/a', 'tree-a')

    expect(restoreSegments('/never-seen')).toBe(false)
  })

  test('refuses unless every boundary can show it', () => {
    // Depth 1 only ever saw /docs. Revealing /a at depth 2 while depth 1
    // showed something else would compose two different pages.
    setSegment(1, '/docs', 'section')
    setSegment(2, '/docs/a', 'page-a')

    expect(restoreSegments('/docs/a')).toBe(false)
  })

  test('refuses when nothing has been stored at all', () => {
    expect(restoreSegments('/a')).toBe(false)
  })
})

describe('the page you arrived on', () => {
  test('is retained, so you can come back to it', () => {
    // Seeded from the server-rendered children; without this the first page is
    // the one page that cannot be returned to.
    seedSegment(2, '/a', 'server-children')
    setSegment(2, '/b', 'tree-b')

    expect(restoreSegments('/a')).toBe(true)
  })

  test('does not change what is showing', () => {
    setSegment(2, '/b', 'tree-b')
    seedSegment(2, '/a', 'server-children')

    expect(getSegmentState(2)!.activeKey).toBe('/b')
  })

  test('is ignored once that page is already held', () => {
    setSegment(2, '/a', 'navigated')
    seedSegment(2, '/a', 'stale-children')

    expect(getSegmentState(2)!.entries).toHaveLength(1)
    expect(getSegmentState(2)!.entries[0].tree).toBe('navigated')
  })
})

describe('retention', () => {
  test('drops the least recently shown past the limit', () => {
    setSegment(2, '/first', 'a')

    for (let i = 0; i < RETENTION; i++) setSegment(2, `/p${i}`, i)

    // Hidden trees keep their DOM, so the window has to be bounded.
    expect(restoreSegments('/first')).toBe(false)
    expect(getSegmentState(2)!.entries).toHaveLength(RETENTION)
  })

  test('revisiting a page keeps it alive', () => {
    setSegment(2, '/keep', 'a')

    for (let i = 0; i < RETENTION + 2; i++) {
      setSegment(2, '/other', i)
      expect(restoreSegments('/keep')).toBe(true)
    }
  })
})

describe('clearing', () => {
  test('returns every boundary to its server-given children', () => {
    setSegment(1, '/a', 'x')
    setSegment(2, '/a/b', 'y')

    clearSegments()

    expect(getSegmentState(1)).toBeNull()
    expect(getSegmentState(2)).toBeNull()
  })
})

/**
 * A prefetched payload carries a segment depth like any other response.
 *
 * The prefetch is a real request and goes out with the chain the client holds,
 * so the server answers with the page alone. Losing that on a cache hit and
 * treating it as a whole document replaces the root with a page that has no
 * layouts — which is what a hover-then-click produced: content on a blank
 * page, no nav, no sidebar, no stylesheet.
 */
describe('what a cached navigation has to remember', () => {
  test('the shape a cache entry needs', async () => {
    const source = await Bun.file(new URL('../../resources/js/navigate.ts', import.meta.url)).text()

    // Depth and chain travel with the tree, not just alongside the fetch.
    expect(source).toContain('segmentDepth: number')
    expect(source).toContain('layouts: string[] | null')

    // And the chain it was fetched against, since a partial only composes
    // against that one.
    expect(source).toContain('heldWhenFetched')
  })

  test('a cache hit is only used when the held chain still matches', async () => {
    const source = await Bun.file(new URL('../../resources/js/navigate.ts', import.meta.url)).text()

    expect(source).toContain('cached.heldWhenFetched === heldLayouts.join(",")')
  })
})
