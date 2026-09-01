/**
 * Core SPA navigation engine for RSC.
 *
 * Uses module-level state (singleton in the browser bundle).
 * The Flight deserializer is injected by createViteRscApp to avoid
 * duplicate bundling of react-server-dom-webpack.
 */

type ReactNode = unknown;
type Deserializer = (stream: ReadableStream, options: Record<string, unknown>) => Promise<ReactNode>;
type CallServerFn = (id: string, args: unknown[]) => Promise<unknown>;

interface CacheEntry {
  tree: Promise<ReactNode>;
  expiresAt: number;
}

interface InterceptEntry {
  urlPattern: string;
  slot: string;
}

let version = "";
let onNavigate: ((tree: ReactNode) => void) | null = null;
let flightDeserializer: Deserializer | null = null;
let callServerFn: CallServerFn | null = null;
let activeController: AbortController | null = null;
const cache = new Map<string, CacheEntry>();
let interceptManifest: InterceptEntry[] = [];

const DEFAULT_PREFETCH_TTL = 30_000;

export function setVersion(v: string): void {
  version = v;
}

export function setNavigateHandler(fn: (tree: ReactNode) => void): void {
  onNavigate = fn;
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
  onNavigate?.(tree);
}

export function getCallServer(): CallServerFn {
  if (!callServerFn) {
    throw new Error("callServer not initialized. Ensure createViteRscApp() has been called.");
  }
  return callServerFn;
}

function fetchRscPayload(url: string, signal?: AbortSignal, interceptSlot?: string, refererUrl?: string): Promise<Response> {
  const headers: Record<string, string> = {
    "X-RSC": "true",
    "X-RSC-Version": version,
  };

  if (interceptSlot) {
    headers["X-RSC-Intercept"] = interceptSlot;
  }

  if (refererUrl) {
    headers["X-RSC-Referer"] = refererUrl;
  }

  return fetch(url, { headers, signal }).then(async (response) => {
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

export async function navigate(
  url: string,
  opts?: { replace?: boolean; preserveScroll?: boolean }
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

  try {
    const cacheKey = interceptSlot ? `__intercept:${interceptSlot}:${url}` : url;
    const cached = cache.get(cacheKey);
    let treePromise: Promise<ReactNode>;

    if (cached && cached.expiresAt > Date.now()) {
      treePromise = cached.tree;
      cache.delete(cacheKey);
    } else {
      cache.delete(cacheKey);
      const response = await fetchRscPayload(url, controller.signal, interceptSlot ?? undefined, currentUrl);

      const contentType = response.headers.get("Content-Type") ?? "";
      if (!contentType.includes("text/x-component")) {
        window.location.href = url;
        return;
      }

      treePromise = deserializeResponse(response);
    }

    const tree = await treePromise;

    if (controller.signal.aborted) return;

    if (opts?.replace) {
      history.replaceState({ rscUrl: url }, "", url);
    } else {
      history.pushState({ rscUrl: url }, "", url);
    }

    onNavigate?.(tree);

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
  const existing = cache.get(cacheKey);

  if (existing && existing.expiresAt > Date.now()) {
    return;
  }

  cache.delete(cacheKey);

  const tree = fetchRscPayload(url, undefined, interceptSlot, refererUrl)
    .then((response) => deserializeResponse(response))
    .catch(() => {
      cache.delete(cacheKey);
      return null;
    });

  cache.set(cacheKey, {
    tree,
    expiresAt: Date.now() + ttl,
  });
}
