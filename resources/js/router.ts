/**
 * Programmatic navigation API for use from client components.
 *
 * Reads from window globals set by createViteRscApp, since client components
 * are built in a separate build graph and cannot directly import navigate.ts.
 */

export function visit(
  url: string,
  opts?: { replace?: boolean }
): Promise<void> {
  const nav = (window as any).__rsc_navigate;

  if (!nav) {
    throw new Error("RSC navigation not initialized. Ensure createViteRscApp has been called.");
  }

  return nav(url, opts);
}

export function prefetch(url: string, cacheForMs?: number): void {
  const fn = (window as any).__rsc_prefetch;

  if (!fn) {
    throw new Error("RSC navigation not initialized. Ensure createViteRscApp has been called.");
  }

  fn(url, cacheForMs);
}

/**
 * Ask the server for the current page again.
 *
 * Without `full`, the layouts already mounted stay and only the page below
 * them is re-rendered — so anything living in a layout, a count in a sidebar
 * say, will not move until you ask for the whole document.
 */
export function refresh(opts?: { full?: boolean }): Promise<void> {
  const fn = (window as any).__rsc_refresh;

  if (!fn) {
    throw new Error("RSC navigation not initialized. Ensure createViteRscApp has been called.");
  }

  return fn(opts);
}

const router = { visit, prefetch, refresh };
export default router;
