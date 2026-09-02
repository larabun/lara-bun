/**
 * Core SPA navigation engine for RSC.
 *
 * Uses module-level state (singleton in the browser bundle).
 * The Flight deserializer is injected by createViteRscApp to avoid
 * duplicate bundling of react-server-dom-webpack.
 */

import { reportReachable } from "./onlineStore";

type ReactNode = unknown;
type Deserializer = (stream: ReadableStream, options: Record<string, unknown>) => Promise<ReactNode>;
type CallServerFn = (id: string, args: unknown[]) => Promise<unknown>;

interface CacheEntry {
  tree: Promise<ReactNode>;
  expiresAt: number;
  /**
   * What the server said about the payload. A prefetch is a real request, so
   * it comes back partial like any other — losing that and treating it as a
   * whole document replaces the root with a page that has no layouts.
   */
  segmentDepth: number;
  layouts: string[] | null;
  /**
   * The chain held when this was prefetched. A partial payload is only valid
   * against the chain it was rendered for; navigate somewhere else first and
   * it no longer composes.
   */
  heldWhenFetched: string;
}

interface InterceptEntry {
  urlPattern: string;
  slot: string;
}

let version = "";
let onNavigate: ((tree: ReactNode, key: string, segmentDepth: number) => void) | null = null;
let onRestore: ((key: string) => boolean) | null = null;
let flightDeserializer: Deserializer | null = null;
let callServerFn: CallServerFn | null = null;
let activeController: AbortController | null = null;
const cache = new Map<string, CacheEntry>();
/** In-flight prefetches, so one the pointer moved away from can be dropped. */
const prefetchControllers = new Map<string, AbortController>();
let interceptManifest: InterceptEntry[] = [];

// The layout chain currently mounted, outermost first. Sent so the server can
// skip re-rendering the layouts still on screen.
let heldLayouts: string[] = [];

/**
 * The boundary depth an interception was rendered at, while one is showing.
 *
 * An interceptor replaces a slot on the layout that declares it, so leaving the
 * intercepted view has to re-render that layout for the slot to go back to its
 * default. Left to itself the next navigation shares the whole chain, replaces
 * only the page below it, and the modal stays open over the new one.
 */
let interceptedAtDepth: number | null = null;

const DEFAULT_PREFETCH_TTL = 30_000;

/**
 * Where a payload lives when there is no server to negotiate with.
 *
 * Normally the payload and the page share a url and are told apart by the
 * X-RSC header. A static host cannot vary by header — it serves one file per
 * url — so an exported build gives payloads their own addresses and the client
 * asks for those instead.
 */
let staticPayloadSuffix: string | null = null;

export function setStaticPayloads(suffix: string | null): void {
  staticPayloadSuffix = suffix;
}

/** The url to request a payload from, which is the page's own unless exported. */
export function payloadUrl(url: string): string {
  if (staticPayloadSuffix === null) return url;

  const parsed = new URL(url, window.location.origin);
  const path = parsed.pathname.replace(/\/+$/, '');

  return `${path}/${staticPayloadSuffix}${parsed.search}`;
}

export function setVersion(v: string): void {
  version = v;
}

/**
 * The layout chain the client is holding.
 *
 * Seeded from the initial page's response and updated on every navigation, so
 * the next request can say what is already mounted.
 */
export function setHeldLayouts(chain: string[]): void {
  heldLayouts = chain;
}

export function getHeldLayouts(): string[] {
  return heldLayouts;
}

export function setNavigateHandler(fn: (tree: ReactNode, key: string, segmentDepth: number) => void): void {
  onNavigate = fn;
}

/**
 * How the router reveals a page that is still mounted behind the current one.
 *
 * Returning true means the page was restored with its client state intact and
 * no request was made.
 */
export function setRestoreHandler(fn: (key: string) => boolean): void {
  onRestore = fn;
}

export function setDeserializer(fn: Deserializer): void {
  flightDeserializer = fn;
}

export function setCallServer(fn: CallServerFn): void {
  callServerFn = fn;
}

export function setInterceptManifest(entries: InterceptEntry[]): void {
  interceptManifest = entries;
}

/**
 * Check if a URL matches any intercept pattern.
 * Returns the matching slot name, or null if no match.
 */
function matchIntercept(url: string): string | null {
  if (interceptManifest.length === 0) return null;

  let pathname: string;
  try {
    pathname = new URL(url, window.location.origin).pathname;
  } catch {
    pathname = url.split("?")[0];
  }

  for (const entry of interceptManifest) {
    // urlPattern already has a leading slash (e.g. "/docs/item/[id]")
    const regex = new RegExp(
      "^" +
        entry.urlPattern
          .replace(/\[\.\.\.(\w+)\]/g, "(.+)")
          .replace(/\[(\w+)\]/g, "([^/]+)") +
        "$"
    );

    if (regex.test(pathname)) {
      return entry.slot;
    }
  }

  return null;
}

export function renderTree(tree: ReactNode): void {
  onNavigate?.(tree, retentionKey(window.location.href, null), 0);
}

/**
 * Identity of a page for retention purposes.
 *
 * Path and query only: a hash is a position within the same page, and an
 * intercepted route is a different rendering of the same URL, so it retains
 * separately from the full page.
 */
export function retentionKey(url: string, interceptSlot: string | null): string {
  let path: string;

  try {
    const parsed = new URL(url, window.location.origin);
    path = parsed.pathname + parsed.search;
  } catch {
    path = url.split("#")[0];
  }

  return interceptSlot ? `__intercept:${interceptSlot}:${path}` : path;
}

export function getCallServer(): CallServerFn {
  if (!callServerFn) {
    throw new Error("callServer not initialized. Ensure createViteRscApp() has been called.");
  }
  return callServerFn;
}

function fetchRscPayload(
  url: string,
  signal?: AbortSignal,
  interceptSlot?: string,
  refererUrl?: string,
  chain: string[] = heldLayouts,
  // A prefetch is speculative and a navigation is not, but on the wire they
  // were identical — so a click could queue behind several prefetches the user
  // had already moved past. Over HTTP/1.1 a browser opens ~6 connections per
  // origin, which a sweep across a nav bar fills on its own.
  priority: "high" | "low" = "high",
): Promise<Response> {
  const headers: Record<string, string> = {
    "X-RSC": "true",
    "X-RSC-Version": version,
  };

  if (chain.length) {
    headers["X-RSC-Segments"] = chain.join(",");
  }

  if (interceptSlot) {
    headers["X-RSC-Intercept"] = interceptSlot;
  }

  if (refererUrl) {
    headers["X-RSC-Referer"] = refererUrl;
  }

  // `priority` is not in every lib.dom yet; browsers without it ignore it.
  const request = fetch(payloadUrl(url), { headers, signal, priority } as RequestInit).catch((err: unknown) => {
    // Nothing answered at all. An abort is our own doing, not the network's —
    // leaving a link cancels its prefetch, and that must not read as offline.
    if (!(err instanceof DOMException && err.name === "AbortError")) {
      reportReachable(false);
    }

    throw err;
  });

  return request.then(async (response) => {
    // Something answered, whatever it said. A 500 is a reachable server.
    reportReachable(true);
    // Adopt the server's build version from the first response that carries
    // one. Until we know it we send an empty version, which the middleware
    // treats as "no opinion"; afterwards a redeploy mid-session answers 409.
    const served = response.headers.get("X-RSC-Version");

    if (served && version === "") {
      version = served;
    }

    if (response.status === 409) {
      const location = response.headers.get("X-RSC-Location");
      window.location.href = location ?? url;
      throw new Error("Version mismatch — full reload triggered");
    }

    return response;
  });
}

/**
 * Deserialize a Flight response into a React tree.
 *
 * Client modules, CSS <link>s and <title>/<meta> all travel inside the Flight
 * payload — @vitejs/plugin-rsc emits stylesheet links as tree elements and
 * resolves client references through its own browser runtime, and React 19
 * hoists document metadata into <head>. Nothing needs injecting from headers.
 */
function deserializeResponse(response: Response): Promise<ReactNode> {
  return flightDeserializer!(response.body!, {
    callServer: callServerFn ?? (async () => {
      throw new Error("Server actions not initialized");
    }),
  });
}

function isExternalUrl(url: string): boolean {
  try {
    return new URL(url, window.location.origin).origin !== window.location.origin;
  } catch {
    return false;
  }
}

/**
 * Whether a cached payload can be used for a navigation claiming this chain.
 *
 * A partial payload only composes against the chain it was rendered for, so
 * the chain it was fetched against has to be the one the navigation is about
 * to claim — not whatever happens to be mounted.
 */
function isUsable(cached: CacheEntry | undefined, chain: string[]): boolean {
  return (
    cached !== undefined &&
    cached.expiresAt > Date.now() &&
    cached.heldWhenFetched === chain.join(",")
  );
}

/**
 * Whether a navigation to this url would be served from the prefetch cache.
 *
 * Lets a caller decide whether showing an already-fetched page is free. A form
 * uses it to put its target route's shell on screen while the real query runs:
 * worth doing when the shell is in hand, never worth an extra request.
 */
export function isPrefetched(url: string): boolean {
  if (isExternalUrl(url)) return false;

  const interceptSlot = matchIntercept(url);
  const cacheKey = interceptSlot ? `__intercept:${interceptSlot}:${url}` : url;

  return isUsable(cache.get(cacheKey), claimedChain(interceptSlot));
}

/**
 * The layout chain a navigation to a url will claim.
 *
 * Leaving an interception claims fewer layouts than are held, which is what
 * forces the layout owning the slot to render again so the slot can empty.
 * Both sides have to agree on it: a prefetch recorded against a different
 * chain can never be used, so the close of every modal refetched a payload it
 * had already fetched.
 */
function claimedChain(interceptSlot: string | null): string[] {
  return !interceptSlot && interceptedAtDepth !== null
    ? heldLayouts.slice(0, interceptedAtDepth)
    : heldLayouts;
}

export async function navigate(
  url: string,
  opts?: { replace?: boolean; preserveScroll?: boolean; restore?: boolean }
): Promise<void> {
  // External URLs can't be fetched (CORS) — go directly to full page navigation
  if (isExternalUrl(url)) {
    window.location.href = url;
    return;
  }

  // Hash-only URLs — let the browser handle scrolling natively
  if (url.startsWith("#")) {
    window.location.hash = url;
    return;
  }

  // Abort any in-flight navigation
  activeController?.abort();

  // If the initial HTML stream is still loading (Suspense completions streaming),
  // stop it so the single-threaded PHP server can handle the new request.
  if (document.readyState === "loading") {
    window.stop();
  }

  const controller = new AbortController();
  activeController = controller;

  // Check if this URL matches an intercept pattern.
  // If so, send the intercept slot + current URL as referer so the server
  // renders the full tree with the interceptor in the right slot.
  const interceptSlot = matchIntercept(url);
  const currentUrl = interceptSlot
    ? window.location.pathname + window.location.search
    : undefined;

  const activityKey = retentionKey(url, interceptSlot);

  // Back and forward are the browser's own gesture for returning to a page you
  // were just on, so they reveal the retained one — instantly, and with the
  // form you were filling in still filled in. A link is a fresh request: the
  // server may have different data to say, and silently showing a stale page
  // would be the wrong default.
  if (opts?.restore && onRestore?.(activityKey)) {
    // A restored tree carries its own slot contents, so the flag only has to
    // reflect whether what is now showing is an intercepted view.
    if (!interceptSlot) interceptedAtDepth = null;

    if (opts.replace) {
      history.replaceState({ rscUrl: url }, "", url);
    } else {
      history.pushState({ rscUrl: url }, "", url);
    }

    window.dispatchEvent(new CustomEvent("rsc-navigate", { detail: url }));

    return;
  }

  // A prefetched payload was rendered against the chain held at prefetch time.
  let segmentDepth = 0;
  let nextLayouts: string[] | null = null;

  try {
    const cacheKey = interceptSlot ? `__intercept:${interceptSlot}:${url}` : url;
    const cached = cache.get(cacheKey);
    let treePromise: Promise<ReactNode>;

    const chain = claimedChain(interceptSlot);

    // A partial payload only composes against the chain it was rendered for —
    // the one this navigation is about to claim, not whatever is mounted.
    // Hovering a link inside a modal prefetches it against the full chain, so
    // reusing that here would skip the layout holding the modal and leave it
    // open over the page behind it.
    const usable = isUsable(cached, chain);

    if (usable) {
      treePromise = cached!.tree;
      segmentDepth = cached!.segmentDepth;
      nextLayouts = cached!.layouts;
      cache.delete(cacheKey);
    } else {
      cache.delete(cacheKey);

      const response = await fetchRscPayload(url, controller.signal, interceptSlot ?? undefined, currentUrl, chain);

      // The check is for a host that answered the page instead of the
      // payload, which is what a server does when it does not recognise the
      // header. An exported build asks a url that only ever holds a payload,
      // and a file server labels it by extension — commonly
      // application/octet-stream — so the check would reject every navigation
      // and send the browser on a full page load instead.
      const contentType = response.headers.get("Content-Type") ?? "";

      if (staticPayloadSuffix === null && !contentType.includes("text/x-component")) {
        window.location.href = url;
        return;
      }

      segmentDepth = Number(response.headers.get("X-RSC-Segment-Depth") ?? 0) || 0;

      const servedLayouts = response.headers.get("X-RSC-Layouts");
      if (servedLayouts !== null) nextLayouts = servedLayouts === "" ? [] : servedLayouts.split(",");

      treePromise = deserializeResponse(response);
    }

    const tree = await treePromise;

    if (controller.signal.aborted) return;

    if (opts?.replace) {
      history.replaceState({ rscUrl: url }, "", url);
    } else {
      history.pushState({ rscUrl: url }, "", url);
    }

    if (nextLayouts !== null) heldLayouts = nextLayouts;

    interceptedAtDepth = interceptSlot ? segmentDepth : null;

    onNavigate?.(tree, activityKey, segmentDepth);

    if (!opts?.preserveScroll && !interceptSlot) {
      // Wait for React to commit the DOM update before scrolling.
      // Intercepted navigations preserve scroll (e.g. modal over current page).
      requestAnimationFrame(() => {
        window.scrollTo(0, 0);
      });
    }

    window.dispatchEvent(new CustomEvent("rsc-navigate", { detail: url }));
  } catch (err) {
    if (err instanceof DOMException && err.name === "AbortError") return;

    // Without this a navigation that fails does nothing observable: the click
    // clears its own pending state and the page stays as it was, with no
    // error, no fallback and nothing for an app to react to. Dispatched before
    // rethrowing, so a programmatic caller still sees the failure.
    window.dispatchEvent(
      new CustomEvent("rsc-navigate-error", { detail: { url, error: err } })
    );

    throw err;
  } finally {
    if (activeController === controller) {
      activeController = null;
    }
  }
}

export function prefetch(url: string, cacheForMs?: number): void {
  if (isExternalUrl(url)) return;

  const ttl = cacheForMs ?? DEFAULT_PREFETCH_TTL;
  const interceptSlot = matchIntercept(url);

  if (interceptSlot) {
    // Intercepted route — only prefetch the intercepted variant
    const currentUrl = window.location.pathname + window.location.search;
    const cacheKey = `__intercept:${interceptSlot}:${url}`;
    prefetchUrl(cacheKey, url, ttl, interceptSlot, currentUrl);
  } else {
    prefetchUrl(url, url, ttl);
  }
}

function prefetchUrl(
  cacheKey: string,
  url: string,
  ttl: number,
  interceptSlot?: string,
  refererUrl?: string
): void {
  const chain = claimedChain(interceptSlot ?? null);
  const existing = cache.get(cacheKey);

  if (existing && existing.expiresAt > Date.now()) {
    return;
  }

  cache.delete(cacheKey);

  const controller = new AbortController();
  prefetchControllers.set(cacheKey, controller);

  const entry: CacheEntry = {
    tree: Promise.resolve(null),
    expiresAt: Date.now() + ttl,
    segmentDepth: 0,
    layouts: null,
    heldWhenFetched: chain.join(","),
  };

  // Low priority: the browser then lets a real navigation overtake a queue of
  // speculative requests instead of serving them in the order they were made.
  entry.tree = fetchRscPayload(url, controller.signal, interceptSlot, refererUrl, chain, "low")
    .then((response) => {
      entry.segmentDepth = Number(response.headers.get("X-RSC-Segment-Depth") ?? 0) || 0;

      const served = response.headers.get("X-RSC-Layouts");
      if (served !== null) entry.layouts = served === "" ? [] : served.split(",");

      return deserializeResponse(response);
    })
    .catch(() => {
      cache.delete(cacheKey);
      return null;
    })
    .finally(() => {
      // Settled, so there is nothing left to abort. A completed prefetch stays
      // in the cache — only an in-flight one is ever dropped.
      if (prefetchControllers.get(cacheKey) === controller) {
        prefetchControllers.delete(cacheKey);
      }
    });

  cache.set(cacheKey, entry);
}

/**
 * Drop a prefetch that is still in flight — the pointer left the link.
 *
 * The cache entry goes synchronously rather than in the abort's catch: the
 * rejection lands a tick later, and a click in between would find an entry
 * whose tree resolves to null and navigate to a blank page. A prefetch that
 * has already completed is kept; there is no request left to cancel and the
 * payload is still good.
 */
export function cancelPrefetch(url: string): void {
  if (isExternalUrl(url)) return;

  const interceptSlot = matchIntercept(url);
  const cacheKey = interceptSlot ? `__intercept:${interceptSlot}:${url}` : url;
  const controller = prefetchControllers.get(cacheKey);

  if (!controller) return;

  prefetchControllers.delete(cacheKey);
  cache.delete(cacheKey);
  controller.abort();
}
