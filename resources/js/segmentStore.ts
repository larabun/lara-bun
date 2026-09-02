/**
 * Which subtree each segment boundary is currently showing.
 *
 * A navigation today replaces the whole root, which is why client state does
 * not survive one and why every response has to carry the entire document. The
 * store is the seam that lets a navigation instead replace one segment: the
 * boundary at a given depth subscribes here, and swapping an entry re-renders
 * only that subtree, leaving the layouts above it — and their DOM, and their
 * state — untouched.
 *
 * Empty is the meaningful default: a boundary with no entry renders the
 * children the server gave it. That is the whole of the current behaviour, so
 * the boundaries can be in place before anything sends partial responses.
 */

type Segment = unknown
type Listener = () => void

const segments = new Map<number, Segment>()
const listeners = new Map<number, Set<Listener>>()

function notify(depth: number): void {
  for (const listener of listeners.get(depth) ?? []) listener()
}

export function subscribeToSegment(depth: number, listener: Listener): () => void {
  let set = listeners.get(depth)

  if (!set) {
    set = new Set()
    listeners.set(depth, set)
  }

  set.add(listener)

  return () => {
    set!.delete(listener)
    if (set!.size === 0) listeners.delete(depth)
  }
}

export function getSegment(depth: number): Segment {
  return segments.has(depth) ? segments.get(depth) : undefined
}

/** Show `tree` at `depth`, discarding anything the navigation replaced below. */
export function setSegment(depth: number, tree: Segment): void {
  segments.set(depth, tree)

  // A deeper segment belonged to the page being replaced; leaving it would
  // render the old page's subtree inside the new one.
  const stale = [...segments.keys()].filter((d) => d > depth)
  for (const d of stale) segments.delete(d)

  notify(depth)
  for (const d of stale) notify(d)
}

/**
 * Drop every segment, so boundaries fall back to their server-given children.
 *
 * A deployment invalidates them all: a retained layout from the previous build
 * has no claim on being correct for this one.
 */
export function clearSegments(): void {
  const depths = [...segments.keys()]
  segments.clear()
  for (const depth of depths) notify(depth)
}
