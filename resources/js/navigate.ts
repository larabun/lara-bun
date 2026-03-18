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
  payload?: Promise<ArrayBuffer>;
  chunks?: string[];
  title: string | null;
  meta: PageMeta | null;
  expiresAt: number;
}

let version = "";
let onNavigate: ((tree: ReactNode) => void) | null = null;
let flightDeserializer: Deserializer | null = null;
let callServerFn: CallServerFn | null = null;
let activeController: AbortController | null = null;
const cache = new Map<string, CacheEntry>();

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

export function renderTree(tree: ReactNode): void {
  onNavigate?.(tree);
}

export function getCallServer(): CallServerFn {
  if (!callServerFn) {
    throw new Error("callServer not initialized. Ensure createRscApp() has been called.");
  }
  return callServerFn;
}

function fetchRscPayload(url: string, signal?: AbortSignal): Promise<Response> {
  return fetch(url, {
    headers: {
      "X-RSC": "true",
      "X-RSC-Version": version,
    },
    signal,
  }).then((response) => {
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

  // Abort any in-flight navigation
  activeController?.abort();

  // If the initial HTML stream is still loading (Suspense completions streaming),
  // stop it so the single-threaded PHP server can handle the new request.
  if (document.readyState === "loading") {
    window.stop();
  }

  const controller = new AbortController();
  activeController = controller;

  try {
    const cached = cache.get(url);
    let treePromise: Promise<ReactNode>;

    if (cached && cached.expiresAt > Date.now()) {
      // Re-deserialize from cached payload bytes for a fresh tree
      if (cached.payload) {
        treePromise = cached.payload.then((buf) => {
          if (!buf) return null;

          // Load any new browser chunks
          if (cached.chunks) {
            const existingScripts = new Set(
              Array.from(document.querySelectorAll<HTMLScriptElement>("script[src]"))
                .map((s) => s.src)
            );
            for (const chunk of cached.chunks) {
              const absoluteUrl = new URL(chunk, window.location.origin).href;
              if (!existingScripts.has(absoluteUrl)) {
                const script = document.createElement("script");
                script.type = "module";
                script.src = chunk;
                document.head.appendChild(script);
              }
            }
          }

          const stream = new ReadableStream({
            start(c) {
              c.enqueue(new Uint8Array(buf));
              c.close();
            },
          });
          return flightDeserializer!(stream, {
            callServer: callServerFn ?? (async () => { throw new Error("Not initialized"); }),
          });
        });
      } else {
        treePromise = cached.tree;
      }
      if (cached.meta) {
        applyMeta(cached.meta);
      } else if (cached.title) {
        document.title = cached.title;
      }
      cache.delete(url);
    } else {
      cache.delete(url);
      const response = await fetchRscPayload(url, controller.signal);

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

    // A newer navigation may have started while we were waiting
    if (controller.signal.aborted) return;

    if (opts?.replace) {
      history.replaceState({ rscUrl: url }, "", url);
    } else {
      history.pushState({ rscUrl: url }, "", url);
    }

    onNavigate?.(tree);

    if (!opts?.preserveScroll) {
      window.scrollTo(0, 0);
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
  const existing = cache.get(url);

  if (existing && existing.expiresAt > Date.now()) {
    return;
  }

  // Remove stale/failed entries so re-hover works
  cache.delete(url);

  let cachedTitle: string | null = null;
  let cachedMeta: PageMeta | null = null;
  let cachedChunks: string[] | undefined;

  // Collect the raw Flight payload bytes so we can re-deserialize on navigate.
  // The deserialized React tree is tied to the stream lifecycle — it can't be
  // reused after the stream closes. Caching raw bytes lets us create a fresh
  // stream and tree on each navigation.
  const payloadPromise = fetchRscPayload(url).then(async (response) => {
    cachedMeta = parseMetaHeader(response);
    if (!cachedMeta) {
      const rawTitle = response.headers.get("X-RSC-Title");
      cachedTitle = rawTitle ? decodeURIComponent(rawTitle) : null;
    }

    const chunksHeader = response.headers.get("X-RSC-Chunks");
    if (chunksHeader) {
      try { cachedChunks = JSON.parse(chunksHeader); } catch {}
    }

    return response.arrayBuffer();
  }).catch(() => {
    cache.delete(url);
    return null;
  });

  // The tree Promise resolves lazily from the payload
  const tree = payloadPromise.then((buf) => {
    if (!buf) return null;
    const stream = new ReadableStream({
      start(controller) {
        controller.enqueue(new Uint8Array(buf));
        controller.close();
      },
    });
    return flightDeserializer!(stream, {
      callServer: callServerFn ?? (async () => { throw new Error("Not initialized"); }),
    });
  });

  cache.set(url, {
    get title() { return cachedTitle; },
    get meta() { return cachedMeta; },
    get chunks() { return cachedChunks; },
    payload: payloadPromise as Promise<ArrayBuffer>,
    tree,
    expiresAt: Date.now() + ttl,
  });
}
