# Laravel RSC Package

## Overview

React Server Components for Laravel. PHP drives a JavaScript runtime over a local socket to render RSC, stream HTML, call back into PHP, and run server actions.

The package is `larabun/laravel-rsc` (namespace `LaravelRsc\`). The routing layer is a host-agnostic Vite plugin (`rscRoutes()`); Bun is the runtime today but the engine also runs on Node.

## Architecture

- `src/` — PHP package code (Laravel service provider, RuntimeBridge, RSC pipeline)
- `resources/` — TypeScript/JS (Bun worker, RSC handler, build script, client components)
- `resources/js/` — Client-side components (Link, navigate, createRscApp)
- `resources/views/` — Blade templates (rsc-app shell)
- `tests/` — Pest PHP tests (Unit + Feature)

## Key Conventions

- PHP follows Laravel conventions with Pint formatting
- RSC builds run through Vite + @vitejs/plugin-rsc (`resources/build-rsc-vite.ts`)
- Client components use `"use client"` directive
- Server actions use `"use server"` directive
- Socket communication uses a binary frame protocol (4-byte length + JSON)
- Always run `vendor/bin/pint --dirty --format agent` after modifying PHP files

## Testing

Two suites, both run from the package root:

- **PHP (Pest + orchestra/testbench)** — the Laravel layer, with RuntimeBridge mocked.
  - All: `vendor/bin/pest --compact`
  - One file: `vendor/bin/pest tests/Feature/RouteInterceptionTest.php`
- **JS (bun:test)** — the vite RSC engine and the client hooks, which the PHP suite never loads.
  Builds `tests/fixtures/rsc-app` and asserts on what the generated entry renders:
  layout/slot/intercept composition, Suspense streaming order, metadata, client
  references, server actions, and the loading.tsx build validation.
  - `bun test tests/js` (or `bun run test`)
  - `useForm` runs against a real React renderer under happy-dom. Do not force
    `NODE_ENV=production` process-wide in a test file — React only exports
    `act()` from its development build, and the suites share a process.

Add engine-behaviour tests to the JS suite — mocking RuntimeBridge in Pest cannot
catch a rendering regression.

## Development Setup

- Package source: `/Users/ramonmalcolm/Herd/lara-bun` (git repo, branch: `main`) — run the unit/feature suite here
- Integration app: `/Users/ramonmalcolm/Herd/larabun-docs` (git repo, branch: `main`) — real consuming app for end-to-end/manual testing; requires the published `larabun/laravel-rsc` package and ships a Dockerfile
- After package changes: `composer update larabun/laravel-rsc` in consuming apps
- After TS changes: `php artisan rsc:build` to rebuild bundles
- Runtime is `RSC_RUNTIME=bun|node`; the worker and build run on either

## Critical Patterns

### Socket Stream Order
The stream-start frame MUST be yielded before entering the main streaming loop, so HTTP headers flush before slow `php()` callbacks. But the wait for that frame must still service the callback socket (`RuntimeBridge::readStartFrame()`) — the worker resolves page metadata (which may itself call `php()`) before emitting stream-start, so a bare blocking read here deadlocks both sides until the socket timeout. Do not revert to an eager blocking `readFrame()`. Tests in `BunBridgeStreamOrderTest.php` enforce the ordering.

### Callback Drain
Before processing a `php()` callback (which may block), always non-blocking poll the main socket and yield pending stream chunks. This ensures Flight data reaches the browser immediately.

### Shell Before Host Calls
PHP runs a host callback synchronously on the same thread that pumps the HTML
socket, so while one is in flight nothing the worker writes reaches the browser.
The worker therefore drains everything React has already queued — the whole
shell, with every Suspense fallback in it — before releasing the deferred host
calls (`drainQueuedChunks` in `resources/runtime.ts`). Releasing after only the
first chunk strands the rest of the shell for the length of the call. The
release is a stream-quiet signal, never a timer: the `setTimeout` in the worker
is a deadlock backstop and must stay long enough that it cannot race a cold
start. Tests in `tests/js/streaming.test.ts` enforce this.

A consequence, not a bug: a slow host call still delays Suspense *completions*
for its duration, because PHP cannot pump the socket while running app code.
Fallbacks paint immediately; boundaries behind a 2.5s call resolve after it.

### Activity Retention Needs a Non-Document Root
`<Activity mode="hidden">` keeps a page mounted so returning to it restores its
client state — a half-typed form survives. It needs a wrapper above the page,
and React will not hydrate a *document* container through one: the root child of
a document must be `<html>`, and wrapping it does not warn, it hangs the
renderer (verified on React 19.2.7). So `createViteRscApp` only wraps when the
container is an element; an app whose root layout owns `<html>` hydrates the
tree directly and navigations replace it.

Extending retention to document-rooted apps is an engine change, not a client
one: SPA navigation would have to return only the changed segment instead of a
whole document, so two retained pages do not mean two `<html>` elements.

### The Engine Is a Separate npm Package
The JavaScript half — plugin, build CLI, worker, client runtime — publishes as
`rsc-router` and is backend-agnostic; this Composer package is one host for it.
PHP locates it through `LaravelRsc\\Support\\EnginePath`, which prefers the app's
`node_modules/rsc-router` and falls back to the copy bundled here. Never
reconstruct that path at a call site: three commands used to and two still
pointed at `larabun/lara-bun`, a package name that no longer exists.

Ambient types are split by owner. `rsc-types.d.ts` is the engine's
(`Metadata`, `GenerateMetadata`), copied into the app verbatim.
`rsc-env.d.ts` is generated by the host and declares the host global under
whatever name it configured. The global must be declared in exactly one of
them — two ambient declarations of the same function conflict.

### The Plugin Assumes No Backend
`rscRoutes()` is published on its own, so it defaults to nothing a particular
host does: no `route.php`, no `laravel-rsc` import prefix, no
`resources/js/rsc` or `bootstrap/rsc`. Its defaults are plain Vite ones —
`src/app` in, `dist/client` + `.rsc` out. Laravel's conventions are passed by
`RscBuildCommand` through `RSC_PACKAGE_ALIAS`, `RSC_ROUTE_CONFIG_FILE` and
`RSC_ROUTE_CONFIG_PATTERN`, because a host driving the build out of process
cannot pass a RegExp any other way. `tests/js/generic-host.test.ts` fails if a
backend-shaped default reappears, and builds a host that passes nothing.

### Tailwind Needs @source
The build compiles no CSS itself — it runs the project's own Vite config, so an
app adds `@tailwindcss/vite` there like any Vite project. It must also declare
`@source` for its RSC source directory: server components never enter the client
module graph, and Tailwind's automatic detection roots at the Vite root, so
without it the utilities layer comes out holding only classes scraped from the
generated entries. The build still succeeds — nothing warns. Both halves are
pinned in `tests/js/tailwind.test.ts`. One more ordering trap: Tailwind emits
arbitrary media variants (`min-[901px]:`) *ahead* of the named breakpoints, so a
`md:` rule lands last and wins at every larger width. Declare a real breakpoint
in `@theme` instead.

### Host-Owned Manifests
Two artifacts are generated by PHP before the bundle build, because PHP owns
route and action discovery and the client needs the result up front:
`server-actions.generated.ts` + `rsc-env.d.ts` (in `source_dir`) and
`intercept-manifest.json`
(in the out dir, inlined into the generated browser entry). The Vite migration
dropped both, which left the actions calling a global that no longer existed and
the client's intercept manifest permanently empty — every intercepted link fell
through to a full-page navigation. Neither failure is visible at build time, so
`rsc:build` regenerates both on every run. The host global's name lives in
`rsc.host_global` and reaches the plugin as `RSC_HOST_GLOBAL`, so the codegen and
the plugin cannot disagree about what it is called.

### Slots Belong to the Layout That Declares Them
Parallel slots are collected by walking up from the page to the app root, so an
`@slot` directory sits at some level and belongs to the layout in that
directory. Composition attributes each slot by its own component path —
`app/docs/@modal/default` is declared in `app/docs` — rather than handing every
slot to the innermost layout, which drops any the innermost does not declare.
Nothing errors when that happens: the page renders and the modal is simply
absent.

Assert this on rendered HTML, not the Flight payload. An unused prop is still
serialized, so the payload contains the slot component whether or not any
layout rendered it — which is how a test for this passed while the bug was
live.

### Route Interception
- `(.)/(..)/(...) ` patterns in `@slot` directories are intercepted routes
- Intercept pages are excluded from normal route registration
- Prefetch cache uses `__intercept:slot:url` key to separate intercepted vs full-page responses
- The `X-RSC-Intercept` and `X-RSC-Referer` headers control server-side interception
