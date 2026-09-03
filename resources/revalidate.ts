// Marking part of a page stale from inside a server action.
//
// The point is one round trip. An action that changes an order could tell the
// browser to go and re-fetch something, but then the answer the user is
// waiting on arrives before the screen is right, and the fix costs another
// request. Marking instead lets the action's own answer carry the re-rendered
// region back with it — the client applies it and never knows it asked.
//
//   'use server'
//   export async function addOrder(order) {
//     await orders.insert(order)
//     revalidate('orders')       // the section or slot by that name
//     return { ok: true }
//   }
//
// Scoped to the action that is running, not global, because two requests can
// be in flight at once and marking is per-request state. In Laravel this is a
// `scoped()` binding on the container; here it is AsyncLocalStorage, which is
// the same idea with the same lifetime.

import { AsyncLocalStorage } from 'node:async_hooks'

/**
 * One store, however many copies of this module exist.
 *
 * The app's actions are bundled into the server bundle; the host that runs
 * them is not. So this module is loaded twice, and two AsyncLocalStorage
 * instances mean the action marks in one and the host reads the other —
 * everything appears to work, the action's answer simply never carries
 * anything back, and no error is raised on either side.
 *
 * The engine installs its host callable on globalThis for exactly this
 * reason. This is the same crossing.
 */
const SCOPE = Symbol.for('rsc-router.revalidation-scope')

type Store = AsyncLocalStorage<Set<string>>

const globals = globalThis as Record<symbol, unknown>

const scope: Store = (globals[SCOPE] as Store) ?? (globals[SCOPE] = new AsyncLocalStorage<Set<string>>())

/**
 * Mark a region of the current page for re-rendering.
 *
 * `'page'` and `'all'` re-render the page or the whole document; any other
 * name is a section or a parallel slot. Unknown names are refused by the
 * renderer with the names it does know, rather than quietly refreshing
 * nothing.
 *
 * Outside an action this does nothing rather than throwing: it is reasonable
 * for shared code to mark, and unreasonable for that to fail when the same
 * function is called during an ordinary render.
 */
export function revalidate(target: string): void {
  scope.getStore()?.add(target)
}

/** Whether anything is listening, for a host that wants to warn about the rest. */
export function isRevalidating(): boolean {
  return scope.getStore() !== undefined
}

/**
 * Run an action with somewhere for its marks to go, and collect them.
 *
 * The marks are read *after* the action has run, because what it invalidated
 * is only known once its work is done.
 */
export async function withRevalidation<T>(
  run: (taken: () => string[]) => Promise<T>,
): Promise<T> {
  const marked = new Set<string>()

  return await scope.run(marked, () => run(() => [...marked]))
}
