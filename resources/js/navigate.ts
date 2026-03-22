/**
 * Core SPA navigation engine for RSC.
 *
 * Uses module-level state (singleton in the browser bundle).
 * The Flight deserializer is injected by createRscApp to avoid
 * duplicate bundling of react-server-dom-webpack.
 */

type ReactNode = unknown;
type Deserializer = (stream: ReadableStream, options: Record<string, unknown>) => Promise<ReactNode>;
type CallServerFn = (id: string, args: unknown[]) => Promise<unknown>;

interface PageMeta {
  title?: string;
  description?: string;
  [key: string]: string | undefined;
}

interface CacheEntry {
  tree: Promise<ReactNode>;
  title: string | null;
  meta: PageMeta | null;
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

function applyMeta(meta: PageMeta): void {
  if (meta.title) {
    document.title = meta.title;
  }

  for (const [key, value] of Object.entries(meta)) {
    if (key === "title" || !value) continue;

    const isOg = key.startsWith("og:");
    const selector = isOg
      ? `meta[property="${key}"]`
      : `meta[name="${key}"]`;

    let el = document.head.querySelector(selector);

    if (!el) {
      el = document.createElement("meta");
      if (isOg) {
        el.setAttribute("property", key);
      } else {
        el.setAttribute("name", key);
      }
      document.head.appendChild(el);
    }

    el.setAttribute("content", value);
  }
}

function parseMetaHeader(response: Response): PageMeta | null {
  const raw = response.headers.get("X-RSC-Meta");
  if (!raw) return null;
  try {
    return JSON.parse(raw) as PageMeta;
  } catch {
    return null;
  }
}

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
    throw new Error("callServer not initialized. Ensure createRscApp() has been called.");
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
    if (response.status === 409) {
      const location = response.headers.get("X-RSC-Location");
      window.location.href = location ?? url;
      throw new Error("Version mismatch — full reload triggered");
    }

    return response;
  });
}

function deserializeResponse(response: Response): Promise<ReactNode> {
  const chunksHeader = response.headers.get("X-RSC-Chunks");

  if (chunksHeader) {
    try {
      const chunks: string[] = JSON.parse(chunksHeader);
      const existingScripts = new Set(
        Array.from(document.querySelectorAll<HTMLScriptElement>("script[src]"))
          .map((s) => s.src)
      );

      for (const chunk of chunks) {
        const absoluteUrl = new URL(chunk, window.location.origin).href;
        if (!existingScripts.has(absoluteUrl)) {
          const script = document.createElement("script");
          script.type = "module";
          script.src = chunk;
          document.head.appendChild(script);
        }
      }
    } catch {
      // Ignore malformed chunks header
    }
  }

  // Load page-specific CSS — add new links, remove old page CSS
  const cssHeader = response.headers.get("X-RSC-CSS");

  if (cssHeader) {
    try {
      const cssUrls: string[] = JSON.parse(cssHeader);
      const existingLinks = new Set(
        Array.from(document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"][data-rsc-css]'))
          .map((l) => l.href)
      );

      const newAbsoluteUrls = new Set(
        cssUrls.map((u) => new URL(u, window.location.origin).href)
      );

      // Add new CSS links first and wait for them to load before removing old ones.
      // This prevents a flash of unstyled content when CSS hashes change (e.g. HMR rebuild).
      const loadPromises: Promise<void>[] = [];

      for (const cssUrl of cssUrls) {
        const absoluteUrl = new URL(cssUrl, window.location.origin).href;
        if (!existingLinks.has(absoluteUrl)) {
          const link = document.createElement("link");
          link.rel = "stylesheet";
          link.href = cssUrl;
          link.setAttribute("data-rsc-css", "");
          loadPromises.push(new Promise<void>((resolve) => {
            link.onload = () => resolve();
            link.onerror = () => resolve();
          }));
          document.head.appendChild(link);
        }
      }

      // Once new CSS is loaded, remove old links that are no longer needed
      if (loadPromises.length > 0) {
        Promise.all(loadPromises).then(() => {
          document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"][data-rsc-css]')
            .forEach((link) => {
              if (!newAbsoluteUrls.has(link.href)) {
                link.remove();
              }
            });
        });
      } else {
        // No new links to load — remove stale ones immediately
        document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"][data-rsc-css]')
          .forEach((link) => {
            if (!newAbsoluteUrls.has(link.href)) {
              link.remove();
            }
          });
      }
    } catch {
      // Ignore malformed CSS header
    }
  }

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
      if (cached.meta) {
        applyMeta(cached.meta);
      } else if (cached.title) {
        document.title = cached.title;
      }
      cache.delete(cacheKey);
    } else {
      cache.delete(cacheKey);
      const response = await fetchRscPayload(url, controller.signal, interceptSlot ?? undefined, currentUrl);

      const contentType = response.headers.get("Content-Type") ?? "";
      if (!contentType.includes("text/x-component")) {
        window.location.href = url;
        return;
      }

      const meta = parseMetaHeader(response);
      if (meta) {
        applyMeta(meta);
      } else {
        const rawTitle = response.headers.get("X-RSC-Title");
        if (rawTitle) {
          document.title = decodeURIComponent(rawTitle);
        }
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

  let cachedTitle: string | null = null;
  let cachedMeta: PageMeta | null = null;

  const tree = fetchRscPayload(url, undefined, interceptSlot, refererUrl).then((response) => {
    cachedMeta = parseMetaHeader(response);
    if (!cachedMeta) {
      const rawTitle = response.headers.get("X-RSC-Title");
      cachedTitle = rawTitle ? decodeURIComponent(rawTitle) : null;
    }
    return deserializeResponse(response);
  }).catch(() => {
    cache.delete(cacheKey);
    return null;
  });

  cache.set(cacheKey, {
    get title() { return cachedTitle; },
    get meta() { return cachedMeta; },
    tree,
    expiresAt: Date.now() + ttl,
  });
}
