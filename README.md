# rsc-kit for Laravel

React Server Components in Laravel: server-rendered React, streamed HTML, PHP
callables and server actions, over a local socket.

This is the Laravel host for [rsc-kit](https://github.com/rsc-kit/rsc-kit). The
engine is `@rsc-kit/core` on npm and is backend-agnostic; this package drives it
from PHP.

```sh
composer require rsc-kit/laravel
```

## Moving from larabun/lara-bun

This package was `larabun/lara-bun`, in the `LaravelRsc\` namespace. Both
changed at `v0.1.0`:

| | before | now |
| --- | --- | --- |
| package | `larabun/lara-bun` | `rsc-kit/laravel` |
| namespace | `LaravelRsc\` | `RscKit\` |
| provider | `LaravelRscServiceProvider` | `RscKitServiceProvider` |

```sh
composer remove larabun/lara-bun
composer require rsc-kit/laravel
```

then replace `LaravelRsc\` with `RscKit\` throughout — imports, any
`config/app.php` provider entry, and any class reference in config.

**The version went backwards on purpose.** `larabun/lara-bun` reached `v0.8.3`;
this starts again at `0.1.0`, because the number now says which engine it
expects — `rsc-kit/laravel` 0.1.x pairs with `@rsc-kit/core` 0.1.x. It is a new
package name on Packagist, so nothing is overwritten and the old versions stay
installable.

## Features

- **React Server Components** — Server-rendered React with zero client JS for server components
- **File-based routing** — Next.js App Router conventions (pages, layouts, route groups, dynamic segments)
- **PHP callables** — Call Eloquent, auth, sessions directly from server components via `php()`
- **Server actions** — `"use server"` functions for form mutations
- **Streaming HTML** — Suspense boundaries stream progressively over the wire
- **Partial Prerendering (PPR)** — Static shell cached at build time, dynamic content streamed at runtime
- **Parallel routes** — `@folder` convention for named layout slots
- **Route interception** — `(.)/(..)/(...)`  convention for modals on SPA navigation
- **Typed routes** — Auto-generated type-safe `route()` helper
- **Inertia SSR** — Drop-in replacement for Inertia's Node SSR server
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
- [Bun](https://bun.sh) 1.0+
- React 19

## Documentation

Full documentation, guides, and live demos at **[larabun.dev](https://larabun.dev)**

## Performance

| | Avg | Min | Max |
|---|---|---|---|
| **LaraBun (Unix Socket)** | **2.39ms** | **1.73ms** | **4.75ms** |
| Inertia HTTP SSR (Bun) | 3.36ms | 2.32ms | 19.47ms |

~30% faster with zero additional PHP memory overhead. Unix sockets skip the TCP stack entirely.

## Support

If this saved you time, consider supporting the project:

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-support-yellow?logo=buy-me-a-coffee&logoColor=white)](https://buymeacoffee.com/ramonmalcolm)

## License

MIT
