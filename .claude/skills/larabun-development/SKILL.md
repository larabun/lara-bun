---
name: larabun-development
description: "Develops LaraBun applications — React Server Components, file-based routing, Forms, useForm, server actions, php() callables, optimistic updates, streaming, Suspense, and the build pipeline."
license: MIT
metadata:
  author: larabun
---

# RSC Development for LaraBun

## When to Apply

Activate this skill when:

- Creating or modifying RSC pages, layouts, or components
- Working with file-based routing conventions
- Implementing parallel routes (@folder) or route interception
- Adding php() callables or server actions
- Debugging streaming, Suspense, or hydration issues
- Modifying the build pipeline (build-rsc-vite.ts, worker.ts)

## File-Based Routing

```
resources/js/rsc/app/
├── layout.tsx          ← root layout (wraps all pages)
├── page.tsx            ← / route
├── (group)/            ← route group (no URL segment)
│   └── page.tsx
├── [param]/            ← dynamic segment → {param}
│   └── page.tsx
├── [...param]/         ← catch-all → {param} with .*
│   └── page.tsx
├── @slot/              ← parallel route slot
│   ├── page.tsx        ← default slot content
│   └── (.)target/      ← route interception
│       └── page.tsx
├── loading.tsx         ← Suspense fallback (auto-wraps children)
└── route.php           ← middleware, viewData, staticPaths, where
```

## Key Conventions

### Server Components (default)
```tsx
// No directive needed — server by default
export default async function Page({ slug }: { slug: string }) {
  const data = await php<Post>("Posts.find", slug);
  return <div>{data.title}</div>;
}
```

### Client Components
```tsx
"use client";
import { useState } from "react";
export default function Counter() {
  const [count, setCount] = useState(0);
  return <button onClick={() => setCount(count + 1)}>{count}</button>;
}
```

### Server Actions
```tsx
"use server";
export async function addTodo(formData: FormData) {
  const title = formData.get("title") as string;
  return await (globalThis as any).php("Todos.add", title);
}
```

### Form Component
```tsx
"use client";
import { Form } from "lara-bun/router";
import { addTodo } from "./actions";

type FormValues = { title: string };

export default function TodoForm() {
  return (
    <Form<FormValues> action={addTodo}>
      {({ pending, error }) => (
        <>
          <input name="title" />
          {error('title') && <span>{error('title')}</span>}
          <button disabled={pending}>{pending ? 'Adding...' : 'Add'}</button>
        </>
      )}
    </Form>
  );
}
```

### useForm Hook
```tsx
"use client";
import { useForm } from "lara-bun/router";
import { updateProfile } from "./actions";

const { data, setData, errors, error, pending, recentlySuccessful, submit } =
  useForm<{ name: string; email: string }>({ name: '', email: '' });

// Submit with optimistic update
submit(updateProfile, () => addOptimistic(data));
```

### Optimistic Updates
```tsx
const [optimisticTodos, addOptimistic] = useOptimistic(todos,
  (state, newTodo: Todo) => [...state, newTodo]
);

// Form component — optimistic prop receives form data
<Form action={addTodo} optimistic={(data) => addOptimistic({ id: Date.now(), title: data.title })}>

// useForm hook — second arg to submit() runs inside the transition
submit(addTodo, () => addOptimistic({ id: Date.now(), title: data.title }));
```

### PHP Callables
```php
// app/Rsc/Posts.php — auto-discovered
class Posts {
    public function latest(): array {
        return Post::latest()->take(10)->get()->toArray();
    }
}
```

## Route Interception

Convention matches Next.js:

| Prefix | Intercepts |
|--------|-----------|
| `(.)folder` | Same level |
| `(..)folder` | One level up |
| `(...)folder` | From app root |

Layout receives slot as prop — no special wrapper needed:

```tsx
export default function Layout({ children, modal }) {
  return <div>{children}{modal}</div>;
}
```

## Pipeline (PHP → Bun → Browser)

### SPA Navigation
1. Browser `fetch()` with `X-RSC: true` header
2. PHP `PageController` → `RscResponse::toStreamedRscResponse()`
3. PHP `BunBridge::rscStream()` → socket message to Bun worker
4. Bun worker → the generated entry's `handleRscStream()` → Flight stream
5. PHP yields chunks → browser `createFromReadableStream()` → React renders

### Initial HTML Load
Same but uses `rscHtmlStream` → HTML SSR with Suspense streaming

### Route Interception (SPA)
1. Client `matchIntercept()` detects URL in manifest
2. `X-RSC-Intercept: slotName` + `X-RSC-Referer: currentUrl` headers added
3. Server resolves referer page, renders with interceptor in slot override
4. `buildElement()` uses `{component, props}` object for the overridden slot

## Socket Protocol

Binary frames: 4-byte big-endian length + JSON payload.

### Message Types (main socket)
- `rsc-stream` → Flight payload streaming (SPA nav)
- `rsc-html-stream` → HTML + Flight streaming (initial load)
- `rsc-action` → Server action execution
- `rsc-ppr-shell` → PPR shell capture (build time)

### Callback Socket (.sock.cb)
- Persistent pool for `php()` calls during rendering
- PHP registers with `callbackId`, Bun routes responses back

## Critical: Stream-Start Ordering

The `stream-start` frame MUST be read eagerly from the main socket before entering the callback select loop. Before processing any callback, drain pending main socket frames with non-blocking `socket_select(timeout=0)`. This prevents slow `php()` callbacks from blocking response delivery.

## Build System

`resources/build-rsc-vite.ts` — runs Vite with `@vitejs/plugin-rsc`:
- Discovers `page`/`layout`/`loading`/`default` route components under `app/`
- Generates the three plugin entries (rsc / ssr / browser) carrying LaraBun's
  `buildElement` composition and the worker's render contract
- Server bundles land in `bootstrap/rsc/vite`; the browser bundle goes to
  `public/build/rsc-vite` and is served directly, never through PHP
- The plugin handles directive splitting, client references and CSS; React 19
  hoists the `<title>`/`<meta>` rendered inside the tree
### Extending the build

The engine owns the structural config — entries, output dirs, `base` — and has
no opinion about plugins. An app adds its own in `vite.rsc.config.ts` at the
project root (or point `BUN_RSC_VITE_CONFIG` anywhere), and they are merged in
after `rsc()`:

```ts
// vite.rsc.config.ts
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react({ compiler: true })],
})
```

That enables the React Compiler — install `@vitejs/plugin-react` and
`oxc-transform-react` in the app and it runs, no Babel involved. The Babel
route still works if you need its options:

```ts
react({ babel: { plugins: ['babel-plugin-react-compiler'] } })
```

The same file is where Tailwind, extra aliases, or any other plugin belongs.
`rsc()` always runs first, since a `react()` layer has to transform what it has
already split into client and server graphs.

### loading.tsx requirement

A route needs `loading.tsx` only when the page itself blocks before it can
paint — an async default export awaiting `php()`, or a `route.php` resolving
`props()` through a closure. The build fails with the offending route named.

Slow work in a child wrapped in its own `<Suspense>` needs nothing, because the
page still paints a shell immediately. `viewData()` is Blade-only and never
blocks React, so it is ignored.

### Partial prerendering (PPR)

A page whose slow work sits in a child behind `<Suspense>` gets a **shell**:
the static markup with a hole where the request data goes. `rsc:build` writes
it to `.ppr.html`, and `ServeStaticRsc` serves it with `Cache-Control:
public, s-maxage=…` plus an `ETag`, so any CDN caches it with no worker and no
special runtime.

The shell already contains the client bootstrap, so the browser paints it
immediately, boots, and fills the hole from the Flight request — which is
`no-store` and always hits the origin. No request-specific data is ever in a
cached shell: the build renders it with `php()` replaced by a probe that never
resolves.

```
browser → CDN: cached shell (instant paint, Suspense fallback showing)
browser → origin: Flight payload with real data → fills the hole
```

Tunable with `BUN_RSC_SHELL_TTL` and `BUN_RSC_SHELL_SWR`. Two caveats:

- Shells go stale on redeploy. Purge the CDN on deploy, or keep the TTL short.
- If a CSP nonce is active the shell is served `private, no-store`, since one
  cached copy would hand every visitor the same nonce.

### Cache invalidation

Shells are tagged `larabun-shell` via `Cache-Tag` (Cloudflare) and
`Surrogate-Key` (Fastly/Varnish), so a deploy hook can purge every shell at
once instead of waiting out the TTL. **Purge on deploy** — a shell references
hashed asset URLs, and once those 404 the client never boots to fill the hole.
Short of a purge hook, keep `BUN_RSC_SHELL_TTL` low.

The client adopts the build version from the first response carrying
`X-RSC-Version`, so a redeploy mid-session is caught on the next navigation and
answered with a 409 plus a full reload.

## Deployment

`bun:serve` is a long-running supervisor that spawns the Bun workers; PHP talks
to them over a Unix socket. Any host that can run a persistent process
alongside PHP works — the two only need to share a filesystem.

### Laravel Cloud

Runs on any plan, including Starter and Growth. No enterprise plan and no TCP
transport are required.

1. **Build commands** — install Bun before building, since Cloud's PHP image
   has none. `bun:install` writes a static binary to `bin/bun` inside the
   project, so it persists into the deployed image:

   ```bash
   php artisan bun:install && php artisan rsc:build
   ```

2. **App cluster → Background processes → Custom worker** — command
   `php artisan bun:serve`, 1 instance. Cloud restarts it if it exits.

   Use the **App** cluster, not a worker cluster. Background processes there run
   in the same pod that serves web traffic, so the Unix socket works. Worker
   clusters are separate compute that does not serve web traffic, so PHP could
   not reach a worker running on one.

3. **Set `BUN_WORKERS`** — Cloud spawns your custom process once *per replica*,
   and each Bun worker loads the RSC bundle into its own heap. `BUN_WORKERS=1`
   is right for small instances. Left unset, the default is bounded by the
   cgroup CPU quota and the container memory limit, but setting it explicitly is
   clearer.

Keep `BUN_TRANSPORT=unix` (the default).

**Scale to Zero** stops the App cluster on its sleep timeout, taking the Bun
workers with it; they restart when the environment wakes. PHP retries the socket
connection for up to 3s to cover that. A manual wake interval avoids the cold
start entirely.
