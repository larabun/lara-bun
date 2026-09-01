// Vite-engine client bootstrap. Uses @vitejs/plugin-rsc's browser runtime as
// the Flight deserializer + action encoder, and drives LaraBun's engine-agnostic
// navigate.ts SPA engine (Link, prefetch, popstate) through it. This replaces
// the bun engine's createRscApp + the hand-rolled webpack shim — the plugin
// resolves client references itself.
import { createFromReadableStream, encodeReply, setServerCallback } from "@vitejs/plugin-rsc/browser";
import { hydrateRoot } from "react-dom/client";
import type { ReactNode } from "react";
import {
  navigate,
  prefetch,
  setCallServer,
  setDeserializer,
  setNavigateHandler,
  setVersion,
} from "./navigate";

export async function createViteRscApp(container: Document | Element = document): Promise<void> {
  async function callServer(id: string, args: unknown[]): Promise<unknown> {
    const encoded = await encodeReply(args);

    // encodeReply returns FormData as soon as an argument contains a File.
    // Sending that as multipart would make PHP consume php://input while
    // parsing it, leaving the action with an empty body — so serialize it to
    // raw bytes under an opaque content-type and send the real one in
    // X-RSC-Content-Type for the worker to rebuild FormData from.
    let body: BodyInit;
    let realContentType: string;

    if (encoded instanceof FormData) {
      const serialized = new Response(encoded);
      body = await serialized.arrayBuffer();
      realContentType = serialized.headers.get("content-type") ?? "multipart/form-data";
    } else {
      body = encoded as BodyInit;
      realContentType = "text/plain;charset=UTF-8";
    }

    const res = await fetch("/_rsc/action", {
      method: "POST",
      headers: {
        "X-RSC-Action": id,
        "X-RSC-Content-Type": realContentType,
        "Content-Type": "application/octet-stream",
        "X-XSRF-TOKEN": decodeURIComponent(
          document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? "",
        ),
      },
      body,
    });

    return createFromReadableStream(res.body!, { callServer });
  }

  setDeserializer(createFromReadableStream as never);
  setCallServer(callServer);
  // The plugin's own "use server" client stubs route through its registered
  // server callback — register the same transport there too.
  setServerCallback(callServer as never);

  // Link / Form / router live in a separate build graph and reach the SPA
  // engine through these globals.
  (window as unknown as { __rsc_navigate: typeof navigate }).__rsc_navigate = navigate;
  (window as unknown as { __rsc_prefetch: typeof prefetch }).__rsc_prefetch = prefetch;

  // Hydrate from LaraBun's RSC endpoint (same url + X-RSC, no version header).
  const res = await fetch(window.location.href, { headers: { "X-RSC": "1" } });

  // Seed the SPA engine with the build this page was served from, so a
  // redeploy mid-session is caught on the next navigation. This matters most
  // behind a CDN, where the shell may be cached from an older build.
  const servedVersion = res.headers.get("X-RSC-Version");

  if (servedVersion) {
    setVersion(servedVersion);
  }
  const tree = await createFromReadableStream(res.body!, { callServer });
  const root = hydrateRoot(container, tree as ReactNode, {
    onRecoverableError(error: unknown, errorInfo: unknown) {
      // A PPR shell is served with its Suspense boundaries deliberately
      // unfinished — the build aborts the render once the static part is out.
      // React reports that as #419 and client-renders the boundary from the
      // Flight payload, which is the intended path, not a fault to report.
      const message = String((error as { message?: string })?.message ?? error);

      if (message.includes("419") || message.includes("did not finish this Suspense boundary")) {
        return;
      }

      console.error(error, errorInfo);
    },
  });

  setNavigateHandler((newTree: ReactNode) => root.render(newTree));

  window.addEventListener("popstate", () => {
    navigate(window.location.href, { replace: true });
  });

  history.replaceState({ rscUrl: window.location.href }, "", window.location.href);
}
