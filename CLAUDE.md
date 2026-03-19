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

- Run all tests: `vendor/bin/pest --compact` (from test app at `/Users/ramonmalcolm/Downloads/lara-bun`)
- Run specific: `vendor/bin/pest tests/Feature/RouteInterceptionTest.php`
- Tests use Mockery for BunBridge mocking
- The test app (`/Users/ramonmalcolm/Downloads/lara-bun`) requires the package via path repository

## Development Setup

- Package source: `/Users/ramonmalcolm/Herd/lara-bun` (git repo, branch: `feature/file-based-router`)
- Test app: `/Users/ramonmalcolm/Downloads/lara-bun` (not a git repo, has vendor/bin/pint)
- Docs app: `/Users/ramonmalcolm/Herd/larabun-docs` (git repo, branch: `main`)
- After package changes: `composer update larabun/lara-bun` in consuming apps
- After TS changes: `php artisan rsc:build` to rebuild bundles

## Critical Patterns

### Socket Stream Order
Stream-start frames MUST be read eagerly from the main socket BEFORE entering the callback select loop. This prevents slow `php()` callbacks from blocking HTTP header delivery. Tests in `BunBridgeStreamOrderTest.php` enforce this.

### Callback Drain
Before processing a `php()` callback (which may block), always non-blocking poll the main socket and yield pending stream chunks. This ensures Flight data reaches the browser immediately.

### Route Interception
- `(.)/(..)/(...) ` patterns in `@slot` directories are intercepted routes
- Intercept pages are excluded from normal route registration
- Prefetch cache uses `__intercept:slot:url` key to separate intercepted vs full-page responses
- The `X-RSC-Intercept` and `X-RSC-Referer` headers control server-side interception
