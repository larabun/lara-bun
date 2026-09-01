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

const router = { visit, prefetch };
export default router;
