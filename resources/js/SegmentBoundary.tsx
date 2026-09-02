"use client";

/**
 * The point in the tree a navigation can replace on its own.
 *
 * Sits between a layout and its children, so showing a different segment
 * re-renders from here down and leaves the layouts above it mounted. Server
 * components cannot be re-rendered on the client, which is why the seam has to
 * be a client component reading from a store rather than the layout itself.
 *
 * With nothing in the store it renders the children the server sent — the
 * behaviour that existed before boundaries did.
 */

import { useSyncExternalStore } from 'react'
import type { ReactNode } from 'react'
import { getSegment, subscribeToSegment } from './segmentStore'

export function SegmentBoundary({ depth, children }: { depth: number; children: ReactNode }) {
  const segment = useSyncExternalStore(
    (listener) => subscribeToSegment(depth, listener),
    () => getSegment(depth),
    // On the server there is never an override: the tree being rendered IS the
    // current one. Returning the same thing keeps hydration from mismatching.
    () => undefined,
  )

  return (segment === undefined ? children : segment) as ReactNode
}
