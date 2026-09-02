/**
 * The store a segment boundary reads from.
 *
 * Its default matters as much as its behaviour: with nothing stored, every
 * boundary renders the children the server sent, which is exactly the
 * behaviour that existed before boundaries did. That is what lets them be
 * introduced before anything sends partial responses.
 */

import { afterEach, describe, expect, test } from 'bun:test'
import {
  clearSegments,
  getSegment,
  setSegment,
  subscribeToSegment,
} from '../../resources/js/segmentStore'

afterEach(() => clearSegments())

describe('defaults', () => {
  test('an unset depth is undefined, which means "render the server tree"', () => {
    expect(getSegment(1)).toBeUndefined()
  })

  test('null is a value, not an absence', () => {
    // A segment can legitimately render nothing; that must not fall back to
    // the stale server children.
    setSegment(1, null)

    expect(getSegment(1)).toBeNull()
  })
})

describe('replacing a segment', () => {
  test('notifies only the boundary at that depth', () => {
    const seen: number[] = []
    subscribeToSegment(1, () => seen.push(1))
    subscribeToSegment(2, () => seen.push(2))

    setSegment(1, 'a')

    expect(seen).toEqual([1])
  })

  test('discards deeper segments, which belonged to the replaced page', () => {
    setSegment(1, 'old-section')
    setSegment(2, 'old-page')

    setSegment(1, 'new-section')

    // Leaving depth 2 would render the previous page inside the new section.
    expect(getSegment(2)).toBeUndefined()
  })

  test('tells the deeper boundary it was discarded', () => {
    setSegment(2, 'old-page')

    let notified = 0
    subscribeToSegment(2, () => notified++)

    setSegment(1, 'new-section')

    expect(notified).toBe(1)
  })

  test('leaves shallower segments alone', () => {
    setSegment(1, 'section')
    setSegment(2, 'page')

    expect(getSegment(1)).toBe('section')
  })
})

describe('unsubscribing', () => {
  test('stops delivering', () => {
    let calls = 0
    const off = subscribeToSegment(1, () => calls++)

    setSegment(1, 'a')
    off()
    setSegment(1, 'b')

    expect(calls).toBe(1)
  })
})

describe('clearing', () => {
  test('returns every boundary to its server-given children', () => {
    setSegment(1, 'a')
    setSegment(2, 'b')

    clearSegments()

    expect(getSegment(1)).toBeUndefined()
    expect(getSegment(2)).toBeUndefined()
  })

  test('notifies the boundaries that were showing something', () => {
    setSegment(1, 'a')
    let notified = 0
    subscribeToSegment(1, () => notified++)

    clearSegments()

    expect(notified).toBe(1)
  })
})
