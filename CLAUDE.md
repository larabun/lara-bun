# LaraBun Package

## Overview

LaraBun bridges Laravel (PHP) and Bun (JavaScript) via Unix sockets for React Server Components, streaming HTML, PHP callables, and server actions.

## Architecture

- `src/` — PHP package code (Laravel service provider, BunBridge, RSC pipeline)
- `resources/` — TypeScript/JS (Bun worker, RSC handler, build script, client components)
- `resources/js/` — Client-side components (Link, navigate, createRscApp)
- `resources/views/` — Blade templates (rsc-app shell)
- `tests/` — Pest PHP tests (Unit + Feature)

## Key Conventions

- PHP follows Laravel conventions with Pint formatting
- TypeScript uses Bun's bundler (not Vite) for RSC builds
- Client components use `"use client"` directive
- Server actions use `"use server"` directive
- Socket communication uses a binary frame protocol (4-byte length + JSON)
- Always run `vendor/bin/pint --dirty --format agent` after modifying PHP files

## Testing

- The package has a standalone Pest suite (orchestra/testbench). Run it from the package root:
- Run all tests: `vendor/bin/pest --compact`
- Run specific: `vendor/bin/pest tests/Feature/RouteInterceptionTest.php`
- Tests use Mockery for BunBridge mocking

## Development Setup

- Package source: `/Users/ramonmalcolm/Herd/lara-bun` (git repo, branch: `main`) — run the unit/feature suite here
- Integration app: `/Users/ramonmalcolm/Herd/larabun-docs` (git repo, branch: `main`) — real consuming app for end-to-end/manual testing; requires the published `larabun/lara-bun` package and ships a Dockerfile
- After package changes: `composer update larabun/lara-bun` in consuming apps
- After TS changes: `php artisan rsc:build` to rebuild bundles

## Critical Patterns

### Socket Stream Order
The stream-start frame MUST be yielded before entering the main streaming loop, so HTTP headers flush before slow `php()` callbacks. But the wait for that frame must still service the callback socket (`BunBridge::readStartFrame()`) — the worker resolves page metadata (which may itself call `php()`) before emitting stream-start, so a bare blocking read here deadlocks both sides until the socket timeout. Do not revert to an eager blocking `readFrame()`. Tests in `BunBridgeStreamOrderTest.php` enforce the ordering.

### Callback Drain
Before processing a `php()` callback (which may block), always non-blocking poll the main socket and yield pending stream chunks. This ensures Flight data reaches the browser immediately.

### Route Interception
- `(.)/(..)/(...) ` patterns in `@slot` directories are intercepted routes
- Intercept pages are excluded from normal route registration
- Prefetch cache uses `__intercept:slot:url` key to separate intercepted vs full-page responses
- The `X-RSC-Intercept` and `X-RSC-Referer` headers control server-side interception
