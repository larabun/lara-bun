# rsc-kit for Laravel

React Server Components in Laravel: server-rendered React, streamed HTML, PHP
callables and server actions, over a local socket.

This is the Laravel host for [rsc-kit](https://github.com/rsc-kit/rsc-kit). The
engine is `@rsc-kit/core` on npm and is backend-agnostic; this package drives it
from PHP.

```sh
composer require rsc-kit/laravel
```

## Features

- **React Server Components** — Server-rendered React with zero client JS for server components
- **File-based routing** — Next.js App Router conventions (pages, layouts, route groups, dynamic segments)
- **PHP callables** — Call Eloquent, auth, sessions directly from server components via `rpc()`
- **Server actions** — `"use server"` functions for form mutations
- **Streaming HTML** — Suspense boundaries stream progressively over the wire
- **Partial Prerendering (PPR)** — Static shell cached at build time, dynamic content streamed at runtime
- **Parallel routes** — `@folder` convention for named layout slots
- **Route interception** — `(.)/(..)/(...)`  convention for modals on SPA navigation
- **Typed routes** — The build writes the urls it found, so a link to a page that does not exist fails the typecheck
- **Sub-millisecond IPC** — Binary frame protocol over Unix sockets

## Quick Start

```bash
composer require rsc-kit/laravel
bun add react react-dom react-server-dom-webpack
```

```env
BUN_RSC_ENABLED=true
BUN_BRIDGE_SOCKET=/tmp/my-app-bridge.sock
```

```tsx
// resources/js/rsc/app/page.tsx
export default async function Home() {
  const posts = await php<Post[]>('Posts.latest');
  return (
    <main>
      {posts.map(p => <article key={p.id}><h2>{p.title}</h2></article>)}
    </main>
  );
}
```

```bash
php artisan bun:dev
```

## Requirements

- PHP 8.2+ with the `sockets` extension
- Laravel 11+
- [Bun](https://bun.sh) 1.0+, or Node 24+
- React 19

## Documentation

Full documentation, guides, and live demos at **[rsc-kit.dev](https://rsc-kit.dev)**

## Performance

Rendering a page, measured against Inertia's Node SSR server on the same
machine — a comparison of transports, not a claim to replace it:

| | Avg | Min | Max |
|---|---|---|---|
| **rsc-kit (Unix socket)** | **2.39ms** | **1.73ms** | **4.75ms** |
| Inertia HTTP SSR (Bun) | 3.36ms | 2.32ms | 19.47ms |

A Unix socket skips the TCP stack, which is where most of the difference is.
No additional PHP memory overhead either way.

## Support

If this saved you time, consider supporting the project:

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-support-yellow?logo=buy-me-a-coffee&logoColor=white)](https://buymeacoffee.com/ramonmalcolm)

## License

MIT
