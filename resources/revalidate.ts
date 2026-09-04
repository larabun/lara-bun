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
// `scoped()` binding on the container.

/** What this needs from a runtime: somewhere to keep per-action state. */
interface Scope {
  getStore(): Set<string> | undefined
  run<T>(store: Set<string>, fn: () => T): T
}

/**
 * One store, however many copies of this module exist.
 *
 * The app's actions are bundled into the server bundle; the host that runs
 * them is not. So this module is loaded twice, and two scopes mean the action
 * marks in one and the host reads the other — everything appears to work, the
 * action's answer simply never carries anything back, and no error is raised
 * on either side.
 *
 * The engine installs its host callable on globalThis for exactly this
 * reason. This is the same crossing.
 */
const SCOPE = Symbol.for('rsc-router.revalidation-scope')

const globals = globalThis as Record<symbol | string, unknown>

let ready: Promise<void> | null = null
let warned = false

/**
 * AsyncLocalStorage, wherever this happens to be running.
 *
 * Node, Bun and Deno have it as `node:async_hooks`. A Worker exposes it
 * globally, and only with the right compatibility flags. Importing it
 * statically means this module fails to load on a runtime that has neither —
 * so it is reached for lazily, and there is something to fall back to.
 */
export async function resolveScope(
  // Injectable so the branch a Worker takes can be tested from a runtime that
  // would otherwise never reach it.
  from: Record<string, unknown> = globals,
): Promise<Scope> {
  const Ambient = from.AsyncLocalStorage as (new () => Scope) | undefined

  if (Ambient) return new Ambient()

  try {
    const { AsyncLocalStorage } = await import('node:async_hooks')

    return new AsyncLocalStorage<Set<string>>() as Scope
  } catch {
    return singleFlightScope()
  }
}

/**
 * The fallback, for a runtime with no async context at all.
 *
 * It holds one store and keeps it across the action's awaits, which is right
 * while one action is in flight and wrong the moment two overlap — the second
 * would collect the first's marks. So an overlap is detected and both are
 * emptied: the region is not re-rendered, and the client can still ask for it.
 *
 * Losing a refresh is recoverable. Re-rendering one request's region into
 * another's answer is not, so the safe failure is the one taken here.
 *
 * Exported to be tested. Every runtime this is developed on has async context,
 * so nothing reaches this by accident — and a fallback no test enters is the
 * one that fails on the platform it exists for.
 */
export function singleFlightScope(): Scope {
  let current: Set<string> | undefined
  let running = 0
  let poisoned = false

  return {
    // Poisoned, nothing is marked at all: revalidate() finds no store and
    // does nothing, which is what makes the ambiguity safe rather than
    // merely detected.
    getStore: () => (poisoned ? undefined : current),
    run<T>(store: Set<string>, fn: () => T): T {
      const interrupted = current

      if (running > 0) {
        poisoned = true

        // What was collected before the overlap is just as unattributable as
        // what comes after it.
        interrupted?.clear()
        store.clear()

        if (!warned) {
          warned = true
          console.warn(
            '[rsc-router] Two server actions overlapped on a runtime with no async context, so ' +
              'what they marked for revalidation could not be told apart and was discarded. ' +
              'Enable AsyncLocalStorage — nodejs_compat on Workers — to mark reliably.',
          )
        }
      }

      running++
      current = store

      const restore = () => {
        running--
        current = interrupted

        // Only once nothing is in flight: lifting it while the other action is
        // still running would let its remaining marks through, which is the
        // half of the ambiguity that arrives last.
        if (running === 0) poisoned = false

        if (poisoned) store.clear()
      }

      let result: T

      try {
        result = fn()
      } catch (error) {
        restore()
        throw error
      }

      // Held across the action's awaits, not merely its synchronous part:
      // restoring when fn() *returns* would drop every mark made after the
      // first await, which is where all of them are.
      if (result instanceof Promise) {
        return result.finally(restore) as T
      }

      restore()

      return result
    },
  }
}

/** The scope, once resolved. Null before the first action runs. */
function scope(): Scope | null {
  return (globals[SCOPE] as Scope | undefined) ?? null
}

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
  scope()?.getStore()?.add(target)
}

/** Whether anything is listening, for a host that wants to warn about the rest. */
export function isRevalidating(): boolean {
  return scope()?.getStore() !== undefined
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
  if (!globals[SCOPE]) {
    // Resolved once and shared, for the same reason the scope itself is: a
    // second copy of this module must not end up with a second scope.
    ready ??= resolveScope().then((resolved) => {
      globals[SCOPE] ??= resolved
    })

    await ready
  }

  const marked = new Set<string>()

  return await scope()!.run(marked, () => run(() => [...marked]))
}
