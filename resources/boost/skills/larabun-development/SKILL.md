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
- Modifying the build pipeline (build-rsc.ts, worker.ts, rsc-handler.ts)

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
import { Form } from "lara-bun/form";
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
import { useForm } from "lara-bun/form";
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

### FormRequest in Callables
```php
// Type-hint FormRequest for automatic validation
use App\Http\Requests\StorePostRequest;

class CreatePost {
    public function __invoke(StorePostRequest $request): array {
        return Post::create($request->validated())->toArray();
    }
}
```

### Inline Environment Variables
- `PUBLIC_*` env vars are inlined into browser bundles at build time
- Non-prefixed vars stay server-side only
- Use `process.env.PUBLIC_STRIPE_KEY` in client components
- TypeScript autocomplete via auto-generated `env.d.ts`

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
4. Bun `handleRscStreamMessage` → `renderRscStream()` → Flight stream
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

`resources/build-rsc.ts`:
- Discovers server/client/action components in `app/` directory
- Generates `entry.rsc.tsx` (server bundle with `buildElement`)
- Generates `entry.hydrate.tsx` (browser bundle with `createRscApp`)
- Generates `routes.generated.ts` (typed route helper)
- Generates `intercept-manifest.json` (client-side intercept matching)
- Emits server, SSR, and browser builds with manifests
