import { createFromReadableStream } from "react-server-dom-webpack/client.edge";
import { renderToReadableStream } from "react-dom/server";
import { readFileSync, existsSync } from "node:fs";
import { join, dirname, basename } from "node:path";
import { AsyncLocalStorage } from "node:async_hooks";
import { ServerAuthenticationError, ServerAuthorizationError, ServerRedirectError, ServerValidationError } from "./errors";

interface LayoutEntry {
  component: string;
  props: Record<string, unknown>;
}

// ─── Per-request php() scoping via AsyncLocalStorage ────────────────────────
// Each render runs inside phpContext.run() with its own callback function.
// globalThis.php delegates to the current context's function.

type PhpFn = (functionName: string, ...args: unknown[]) => Promise<unknown>;
const phpContext = new AsyncLocalStorage<PhpFn>();

(globalThis as any).php = (functionName: string, ...args: unknown[]): Promise<unknown> => {
  const fn = phpContext.getStore();
  if (!fn) {
    throw new Error("php() called outside of a render context. Ensure the Bun worker is handling the request.");
  }
  return fn(functionName, ...args);
};

/**
 * Run a function with a scoped php() implementation.
 * All php() calls within the async context will use the provided function.
 */
export function withPhp<T>(phpFn: PhpFn, fn: () => T): T {
  return phpContext.run(phpFn, fn);
}

// ─── Load RSC bundle ────────────────────────────────────────────────────────

const bundlePath = process.env.BUN_RSC_BUNDLE;

if (!bundlePath) {
  throw new Error("BUN_RSC_BUNDLE environment variable is not set");
}

const rscModule = await import(bundlePath);

if (typeof rscModule.renderRsc !== "function") {
  throw new Error(
    "RSC bundle does not export a renderRsc function. Rebuild with: bun run build:rsc"
  );
}

const bundleDir = dirname(bundlePath);

// ─── Load manifests (if they exist) ─────────────────────────────────────────

const clientManifestPath = join(bundleDir, "client-manifest.json");
const ssrManifestPath = join(bundleDir, "ssr-manifest.json");
const browserChunksPath = join(bundleDir, "browser-chunks.json");

let clientManifest: Record<string, unknown> | null = null;
let ssrManifest: {
  moduleMap: Record<string, Record<string, { id: string; chunks: string[]; name: string }>>;
  moduleLoading: null;
  serverModuleMap: Record<string, unknown>;
} | null = null;
let browserChunks: string[] = [];

if (existsSync(clientManifestPath)) {
  clientManifest = JSON.parse(readFileSync(clientManifestPath, "utf-8"));
  console.error("[rsc-handler] Loaded client manifest");
}

if (existsSync(ssrManifestPath)) {
  ssrManifest = JSON.parse(readFileSync(ssrManifestPath, "utf-8"));
  console.error("[rsc-handler] Loaded SSR manifest");
}

if (existsSync(browserChunksPath)) {
  browserChunks = JSON.parse(readFileSync(browserChunksPath, "utf-8"));
  console.error(`[rsc-handler] Browser chunks: ${browserChunks.join(", ")}`);
}

// ─── Shim __webpack_require__ for SSR client component resolution ───────────

const ssrClientDir = join(bundleDir, "client");
const ssrModules: Record<string, unknown> = {};

const ssrModuleMapPath = join(bundleDir, "ssr-module-map.json");
const ssrModuleFileMap: Record<string, string> = existsSync(ssrModuleMapPath)
  ? JSON.parse(readFileSync(ssrModuleMapPath, "utf-8"))
  : {};

if (ssrManifest) {
  for (const moduleId of Object.keys(ssrManifest.moduleMap)) {
    const fileName = ssrModuleFileMap[moduleId]
      ?? basename(moduleId).replace(/\.(tsx|ts|jsx|js|mjs|cjs)$/, "") + ".js";
    const ssrBundlePath = join(ssrClientDir, fileName);

    if (existsSync(ssrBundlePath)) {
      try {
        ssrModules[moduleId] = await import(ssrBundlePath);
      } catch (err) {
        console.error(
          `[rsc-handler] Failed to import SSR bundle for ${moduleId}:`,
          err instanceof Error ? err.message : String(err)
        );
      }
    } else {
      console.error(`[rsc-handler] SSR bundle not found: ${ssrBundlePath}`);
    }
  }
}

const actionManifestPath = join(bundleDir, "action-manifest.json");

if (existsSync(actionManifestPath)) {
  const actionManifest: Record<string, string[]> = JSON.parse(
    readFileSync(actionManifestPath, "utf-8")
  );

  for (const [moduleId, exports] of Object.entries(actionManifest)) {
    const stub: Record<string, Function> = {};
    for (const name of exports) {
      stub[name] = () => {
        throw new Error(`Server action ${moduleId}#${name} cannot be called during SSR`);
      };
    }
    ssrModules[moduleId] = stub;
  }

  console.error(`[rsc-handler] Registered ${Object.keys(actionManifest).length} action module(s) for SSR`);
}

(globalThis as any).__webpack_require__ = function (moduleId: string) {
  const mod = ssrModules[moduleId];
  if (!mod) {
    throw new Error(`[rsc-handler] __webpack_require__: module not found: "${moduleId}"`);
  }
  return mod;
};

(globalThis as any).__webpack_chunk_load__ = function () {
  return Promise.resolve();
};

const emptyManifest = {
  serverConsumerManifest: {
    moduleMap: {},
    moduleLoading: null,
    serverModuleMap: {},
  },
};

// ─── Callback Response Handling ─────────────────────────────────────────────
// Callback responses arrive on the main socket and are routed here.

const pendingCallbacks = new Map<string, {
  resolve: (value: unknown) => void;
  reject: (reason: Error) => void;
}>();

/**
 * Register a pending callback that will be resolved when PHP responds.
 */
export function registerCallback(id: string, resolve: (value: unknown) => void, reject: (reason: Error) => void): void {
  pendingCallbacks.set(id, { resolve, reject });
}

/**
 * Handle a callback response from PHP, routed from the worker's data handler.
 */
export function handleCallbackResponse(response: Record<string, unknown>): void {
  const id = response.id as string;
  const pending = pendingCallbacks.get(id);

  if (!pending) return;

  pendingCallbacks.delete(id);

  if (response.unauthenticated) {
    pending.reject(new ServerAuthenticationError(response.error as string));
  } else if (response.unauthorized) {
    pending.reject(new ServerAuthorizationError(response.error as string));
  } else if (response.validation_errors) {
    pending.reject(new ServerValidationError(
      (response.error as string) ?? "Validation failed",
      response.validation_errors as Record<string, string[]>
    ));
  } else if (response.redirect) {
    pending.reject(new ServerRedirectError(response.redirect as string));
  } else if (response.error) {
    pending.reject(new Error(response.error as string));
  } else {
    pending.resolve(response.result);
  }
}

// ─── Metadata Resolution ─────────────────────────────────────────────────────

export async function resolveMetadata(
  component: string,
  props: Record<string, unknown>,
): Promise<Record<string, unknown> | null> {
  if (typeof rscModule.resolveMetadata !== "function") {
    return null;
  }

  return await rscModule.resolveMetadata(component, props);
}

// ─── Stream Handler (SPA navigation) ─────────────────────────────────────────

export async function handleRscStream(
  component: string,
  props: Record<string, unknown>,
  layouts: LayoutEntry[] = []
): Promise<{ stream: ReadableStream; clientChunks: string[] }> {
  const flightStream: ReadableStream = clientManifest
    ? rscModule.renderRscStream(component, props, clientManifest, layouts)
    : rscModule.renderRscStream(component, props, layouts);

  return { stream: flightStream, clientChunks: browserChunks };
}

// ─── HTML Stream Handler (initial page load with Suspense streaming) ────────

export async function handleRscHtmlStream(
  component: string,
  props: Record<string, unknown>,
  layouts: LayoutEntry[] = []
): Promise<{
  htmlStream: ReadableStream;
  rscPayloadPromise: Promise<string>;
  clientChunks: string[];
}> {
  const flightStream: ReadableStream = clientManifest
    ? rscModule.renderRscStream(component, props, clientManifest, layouts)
    : rscModule.renderRscStream(component, props, layouts);

  const [flightForHtml, flightForPayload] = flightStream.tee();
  const rscPayloadPromise = new Response(flightForPayload).text();

  const consumerManifest = ssrManifest
    ? { serverConsumerManifest: ssrManifest }
    : emptyManifest;

  const reactTree = await createFromReadableStream(flightForHtml, consumerManifest);
  const htmlStream = await renderToReadableStream(reactTree);

  return { htmlStream, rscPayloadPromise, clientChunks: browserChunks };
}

// ─── Action Handler (server actions) ──────────────────────────────────────────

export async function handleAction(
  actionId: string,
  body: string,
  contentType: string,
): Promise<{ stream: ReadableStream }> {
  if (typeof rscModule.getServerAction !== "function") {
    throw new Error("No server actions registered. Rebuild with: bun run build:rsc");
  }

  const hashIndex = actionId.indexOf("#");
  if (hashIndex === -1) {
    throw new Error(`Invalid action ID format: "${actionId}" (expected "moduleId#exportName")`);
  }

  const moduleId = actionId.slice(0, hashIndex);
  const exportName = actionId.slice(hashIndex + 1);
  const actionFn = rscModule.getServerAction(moduleId, exportName);

  if (!actionFn) {
    throw new Error(`Unknown server action: "${actionId}"`);
  }

  let decodable: string | FormData;
  if (contentType.includes("multipart/form-data")) {
    const response = new Response(body, { headers: { "Content-Type": contentType } });
    decodable = await response.formData();
  } else {
    decodable = body;
  }

  const args = await rscModule.decodeReply(decodable);
  const result = await actionFn(...(args as unknown[]));
  const stream = rscModule.renderActionStream(result, clientManifest ?? {});

  return { stream };
}

// ─── PPR Shell Handler (build-time: captures shell with Suspense fallbacks) ──

export async function handleRscPprShell(
  component: string,
  props: Record<string, unknown>,
  layouts: LayoutEntry[] = []
): Promise<{ shellHtml: string; clientChunks: string[]; timedOut: boolean }> {
  // Mock php() — returns Promises that never resolve so components suspend
  const mockPhpFn: PhpFn = (): Promise<never> => new Promise(() => {});

  return withPhp(mockPhpFn, async () => {
    const flightStream: ReadableStream = clientManifest
      ? rscModule.renderRscStream(component, props, clientManifest, layouts)
      : rscModule.renderRscStream(component, props, layouts);

    const consumerManifest = ssrManifest
      ? { serverConsumerManifest: ssrManifest }
      : emptyManifest;

    const reactTree = await createFromReadableStream(flightStream, consumerManifest);
    const htmlStream = await renderToReadableStream(reactTree);
    const reader = htmlStream.getReader();
    const decoder = new TextDecoder();

    let shellHtml = "";
    let timedOut = false;
    const TIMEOUT = 5000;

    while (true) {
      const result = await Promise.race([
        reader.read(),
        new Promise<{ done: true; value: undefined }>((resolve) =>
          setTimeout(() => {
            timedOut = true;
            resolve({ done: true, value: undefined });
          }, TIMEOUT)
        ),
      ]);

      if (result.done) break;

      const chunk = decoder.decode(result.value, { stream: true });
      shellHtml += chunk;

      if (chunk.includes('hidden id="S:') || chunk.includes("$RC(") || chunk.includes("$RS(")) {
        const completionStart = shellHtml.search(/<div hidden id="S:/);
        if (completionStart !== -1) {
          shellHtml = shellHtml.slice(0, completionStart);
        }
        break;
      }
    }

    try { reader.cancel(); } catch {}

    return { shellHtml, clientChunks: browserChunks, timedOut };
  });
}

// ─── Handler (buffered, non-streaming) ───────────────────────────────────────

export async function handleRsc(
  component: string,
  props: Record<string, unknown>,
  layouts: LayoutEntry[] = [],
  isPrerender: boolean = false
): Promise<{ body: string; rscPayload: string; clientChunks: string[]; usedDynamicApis: boolean }> {
  let usedDynamicApis = false;

  // Track dynamic API usage during render (prerender only)
  const originalFetch = globalThis.fetch;
  const originalMathRandom = Math.random;
  const OriginalDate = globalThis.Date;
  const originalRandomUUID = crypto.randomUUID.bind(crypto);
  const originalGetRandomValues = crypto.getRandomValues.bind(crypto);

  if (isPrerender) {
    const markDynamic = () => { usedDynamicApis = true; };

    globalThis.fetch = ((...args: Parameters<typeof fetch>) => {
      markDynamic();
      return originalFetch(...args);
    }) as typeof fetch;

    Math.random = () => { markDynamic(); return originalMathRandom(); };

    globalThis.Date = new Proxy(OriginalDate, {
      construct(target, args) {
        if (args.length === 0) markDynamic();
        return Reflect.construct(target, args);
      },
      apply(target, thisArg, args) {
        markDynamic();
        return Reflect.apply(target, thisArg, args);
      },
      get(target, prop, receiver) {
        if (prop === "now") {
          return () => { markDynamic(); return OriginalDate.now(); };
        }
        return Reflect.get(target, prop, receiver);
      },
    }) as DateConstructor;

    crypto.randomUUID = () => { markDynamic(); return originalRandomUUID(); };
    crypto.getRandomValues = <T extends ArrayBufferView | null>(array: T): T => {
      markDynamic(); return originalGetRandomValues(array);
    };
  }

  try {
    const consumerManifest = ssrManifest
      ? { serverConsumerManifest: ssrManifest }
      : emptyManifest;

    if (isPrerender) {
      // Prerender: use renderRscStream so pending promises (from stub php())
      // don't block. Stream emits static parts immediately, dynamic parts pend.
      const flightStream: ReadableStream = clientManifest
        ? rscModule.renderRscStream(component, props, clientManifest, layouts)
        : rscModule.renderRscStream(component, props, layouts);

      const [flightForHtml, flightForPayload] = flightStream.tee();

      const payloadReader = flightForPayload.getReader();
      const payloadChunks: Uint8Array[] = [];
      let payloadDone = false;
      const collectPayload = (async () => {
        try {
          while (true) {
            const { done, value } = await payloadReader.read();
            if (done) { payloadDone = true; break; }
            payloadChunks.push(value);
          }
        } catch {}
      })();

      const reactTree = await createFromReadableStream(flightForHtml, consumerManifest);
      const htmlStream = await renderToReadableStream(reactTree);

      const PRERENDER_TIMEOUT = 5000;
      const allReadyResult = await Promise.race([
        htmlStream.allReady.then(() => "ready" as const),
        new Promise<"timeout">((resolve) =>
          setTimeout(() => resolve("timeout"), PRERENDER_TIMEOUT)
        ),
      ]);

      if (allReadyResult === "ready") {
        const body = await new Response(htmlStream).text();
        await Promise.race([collectPayload, Bun.sleep(1000)]);
        try { payloadReader.cancel(); } catch {}

        const rscPayload = payloadDone
          ? new TextDecoder().decode(Buffer.concat(payloadChunks))
          : "";

        return { body, rscPayload, clientChunks: browserChunks, usedDynamicApis };
      }

      // Dynamic page — read shell only
      const reader = htmlStream.getReader();
      const decoder = new TextDecoder();
      let body = "";

      while (true) {
        const result = await Promise.race([
          reader.read(),
          new Promise<{ done: true; value: undefined }>((resolve) =>
            setTimeout(() => resolve({ done: true, value: undefined }), PRERENDER_TIMEOUT)
          ),
        ]);

        if (result.done) {
          if (body === "") {
            try { reader.cancel(); } catch {}
            try { payloadReader.cancel(); } catch {}
            throw new Error(
              "Prerender timed out. A component awaits php() without a <Suspense> boundary. " +
              "Wrap async content in <Suspense> or provide a loading.tsx."
            );
          }
          break;
        }

        const chunk = decoder.decode(result.value, { stream: true });
        body += chunk;

        if (chunk.includes('hidden id="S:') || chunk.includes("$RC(")) {
          const idx = body.search(/<div hidden id="S:/);
          if (idx !== -1) body = body.slice(0, idx);
          break;
        }
      }

      try { reader.cancel(); } catch {}
      try { payloadReader.cancel(); } catch {}

      return { body, rscPayload: "", clientChunks: browserChunks, usedDynamicApis };
    }

    // Normal path: buffered render with php() available via AsyncLocalStorage
    const rscPayload: string = clientManifest
      ? await rscModule.renderRsc(component, props, clientManifest, layouts)
      : await rscModule.renderRsc(component, props, layouts);

    const flightStream = new ReadableStream({
      start(controller) {
        controller.enqueue(new TextEncoder().encode(rscPayload));
        controller.close();
      },
    });

    const reactTree = await createFromReadableStream(flightStream, consumerManifest);
    const htmlStream = await renderToReadableStream(reactTree);
    await htmlStream.allReady;

    const body = await new Response(htmlStream).text();

    return { body, rscPayload, clientChunks: browserChunks, usedDynamicApis };
  } finally {
    if (isPrerender) {
      globalThis.fetch = originalFetch;
      Math.random = originalMathRandom;
      globalThis.Date = OriginalDate;
      crypto.randomUUID = originalRandomUUID;
      crypto.getRandomValues = originalGetRandomValues;
    }
  }
}
