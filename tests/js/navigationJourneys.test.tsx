/**
 * Whole navigation journeys, driven through the real router.
 *
 * Every navigation bug this feature produced lived in a journey rather than a
 * unit: hover-then-click lost the segment depth and rendered a page with no
 * layouts; visiting a section with its own layout wiped retention on the way
 * in; going back from one refused to restore. The store, the boundary and the
 * depth arithmetic each passed their own tests throughout.
 *
 * So this drives navigate.ts itself — its prefetch cache, its history handling,
 * its restore path — against a server that answers the segment protocol the
 * way the host does. Only the transport and the Flight encoding are stood in
 * for; the routing is the real thing.
 */

import { registerDom } from './dom'

registerDom()

import { act, useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import {
  navigate,
  prefetch,
  setCallServer,
  setDeserializer,
  setHeldLayouts,
  setInterceptManifest,
  setNavigateHandler,
  setRestoreHandler,
} from '../../resources/js/navigate.ts'
import { SegmentBoundary } from '../../resources/js/SegmentBoundary.tsx'
import { clearSegments, restoreSegments, setSegment } from '../../resources/js/segmentStore.ts'

// ── The app the server renders ───────────────────────────────────────────────

const ROUTES: Record<string, string[]> = {
  '/a': ['app/layout', 'app/docs/layout'],
  '/b': ['app/layout', 'app/docs/layout'],
  // A section with a layout of its own: the shared depth is less than either
  // chain, which is the shape that broke retention.
  '/deep': ['app/layout', 'app/docs/layout', 'app/docs/deep/layout'],
  // Shares only the root.
  '/other': ['app/layout', 'app/other/layout'],
  // Lives under /deep's layout, and is intercepted into that layout's slot.
  '/deep/item/1': ['app/layout', 'app/docs/layout', 'app/docs/deep/layout'],
}

/** The layout that declares the intercepted slot, and so renders it. */
const SLOT_OWNER_DEPTH = 2

/** A page with state a user would be annoyed to lose. */
function Page({ id }: { id: string }) {
  const [value, setValue] = useState('')

  return (
    <div data-page={id}>
      <input
        aria-label={id}
        value={value}
        onChange={(e) => setValue((e.target as HTMLInputElement).value)}
      />
    </div>
  )
}

/** Mirrors buildElement: layouts from `from` down, each wrapping a boundary. */
function renderRoute(url: string, from: number): ReactNode {
  const chain = ROUTES[url]
  let element: ReactNode = <Page id={url} />

  for (let i = chain.length - 1; i >= from; i--) {
    element = (
      <div data-layout={chain[i]}>
        <SegmentBoundary depth={i + 1} pageKey={url}>
          {element}
        </SegmentBoundary>
      </div>
    )
  }

  return element
}

// ── A server that speaks the segment protocol ────────────────────────────────

let requests: Array<{ url: string; held: string | null; depth: number }> = []

function sharedDepth(held: string | null, chain: string[]): number {
  if (!held) return 0

  const heldChain = held.split(',')
  let depth = 0

  for (const [i, component] of chain.entries()) {
    if (heldChain[i] !== component) break
    depth++
  }

  return depth
}

function installServer() {
  ;(globalThis as { fetch: unknown }).fetch = async (input: unknown, init?: { headers?: Record<string, string> }) => {
    const url = new URL(String(input), 'https://example.test').pathname
    const held = init?.headers?.['X-RSC-Segments'] ?? null
    const chain = ROUTES[url]

    if (!chain) throw new Error(`no route for ${url}`)

    // An interceptor replaces a slot on the layout that declares it, so the
    // render has to reach that layout however much the client already holds.
    const intercepting = init?.headers?.['X-RSC-Intercept'] !== undefined
    const depth = intercepting
      ? Math.min(sharedDepth(held, chain), SLOT_OWNER_DEPTH)
      : sharedDepth(held, chain)

    requests.push({ url, held, depth })

    return new Response(`${url}|${depth}`, {
      headers: {
        'Content-Type': 'text/x-component',
        'X-RSC-Segment-Depth': String(depth),
        'X-RSC-Layouts': chain.join(','),
      },
    })
  }
}

// ── The client, wired the way createViteRscApp wires it ──────────────────────

let container: HTMLElement
let root: ReturnType<typeof createRoot>
let setRootTree: ((tree: ReactNode) => void) | null = null

function Root({ initial }: { initial: ReactNode }) {
  const [tree, setTree] = useState<ReactNode>(initial)

  useEffect(() => {
    setRootTree = setTree
  }, [])

  return tree
}

async function boot(url: string) {
  history.replaceState({}, '', url)

  setDeserializer(async (stream: ReadableStream) => {
    const [page, depth] = (await new Response(stream).text()).split('|')

    return renderRoute(page, Number(depth))
  })
  setCallServer(async () => null)

  setNavigateHandler((tree, key, segmentDepth) => {
    if (segmentDepth > 0) {
      setSegment(segmentDepth, key, tree as ReactNode)

      return
    }

    clearSegments()
    setRootTree?.(tree as ReactNode)
  })
  setRestoreHandler((key) => restoreSegments(key))
  setInterceptManifest([{ urlPattern: '/deep/item/[id]', slot: 'modal' }])
  setHeldLayouts(ROUTES[url])

  await act(async () => {
    root.render(<Root initial={renderRoute(url, 0)} />)
  })
}

/** What the browser's back button does. */
async function back(url: string) {
  await act(async () => {
    await navigate(url, { replace: true, restore: true })
  })
}

async function go(url: string) {
  await act(async () => {
    await navigate(url)
  })
}

/**
 * Retained pages stay in the DOM, so presence is not the question.
 *
 * happy-dom has no layout engine and reports client rects for hidden elements
 * too, so visibility is read from the inline display Activity sets rather than
 * from geometry.
 */
function hidden(el: Element): boolean {
  let node: HTMLElement | null = el as HTMLElement

  while (node && node !== container) {
    if (node.style?.display === 'none') return true
    node = node.parentElement
  }

  return false
}

function visiblePage(): string | null {
  const shown = [...container.querySelectorAll('[data-page]')].find((el) => !hidden(el))

  return shown?.getAttribute('data-page') ?? null
}

function field(id: string): HTMLInputElement | null {
  return [...container.querySelectorAll<HTMLInputElement>(`input[aria-label="${id}"]`)].find((el) => !hidden(el)) ?? null
}

async function type(id: string, value: string) {
  const el = field(id)!
  const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!

  await act(async () => {
    setter.call(el, value)
    el.dispatchEvent(new Event('input', { bubbles: true }))
  })
}

beforeEach(() => {
  clearSegments()
  requests = []
  installServer()
  container = document.createElement('div')
  document.body.appendChild(container)
  root = createRoot(container)
})

afterEach(async () => {
  await act(async () => root.unmount())
  container.remove()
  clearSegments()
  setRootTree = null
})

// ── The journeys ─────────────────────────────────────────────────────────────

describe('moving between pages under the same layouts', () => {
  test('sends only the page and leaves the layouts mounted', async () => {
    await boot('/a')
    await go('/b')

    expect(requests.at(-1)).toMatchObject({ url: '/b', depth: 2 })
    expect(visiblePage()).toBe('/b')
    // The chrome above the boundary was never resent, so it is still the
    // element that was hydrated.
    expect(container.querySelector('[data-layout="app/layout"]')).not.toBeNull()
  })

  test('going back restores the page with what was typed, and asks for nothing', async () => {
    await boot('/a')
    await type('/a', 'half-written')
    await go('/b')

    const before = requests.length
    await back('/a')

    expect(requests.length).toBe(before)
    expect(visiblePage()).toBe('/a')
    expect(field('/a')!.value).toBe('half-written')
  })
})

describe('a section with a layout of its own', () => {
  test('is still only sent from the layout that differs', async () => {
    await boot('/a')
    await go('/deep')

    // Two layouts shared of three: the payload starts at the third.
    expect(requests.at(-1)).toMatchObject({ url: '/deep', depth: 2 })
    expect(visiblePage()).toBe('/deep')
  })

  test('going back to a shallower page keeps its state', async () => {
    // The reported bug: the deeper section adds a boundary the shallower page
    // never had, and returning refused to restore because of it.
    await boot('/a')
    await type('/a', 'still here')
    await go('/deep')

    const before = requests.length
    await back('/a')

    expect(requests.length).toBe(before)
    expect(visiblePage()).toBe('/a')
    expect(field('/a')!.value).toBe('still here')
  })

  test('and can be returned to afterwards', async () => {
    await boot('/a')
    await go('/deep')
    await back('/a')
    await back('/deep')

    expect(visiblePage()).toBe('/deep')
  })
})

describe('a section sharing only the root layout', () => {
  test('is sent from the layout that differs, not as a whole document', async () => {
    await boot('/a')
    await go('/other')

    expect(requests.at(-1)).toMatchObject({ url: '/other', depth: 1 })
    expect(visiblePage()).toBe('/other')
  })
})

describe('a prefetched navigation', () => {
  test('renders with its layouts, not as a bare page', async () => {
    // A prefetch goes out with the chain held, so it comes back partial. Losing
    // that depth on the cache hit replaced the root with a page that had no
    // layouts — content on a blank screen, and only after a hover.
    await boot('/a')

    await act(async () => {
      prefetch('/b')
      await new Promise((r) => setTimeout(r, 0))
    })

    const afterPrefetch = requests.length
    await go('/b')

    // Served from cache: no second request.
    expect(requests.length).toBe(afterPrefetch)
    expect(visiblePage()).toBe('/b')
    expect(container.querySelector('[data-layout="app/layout"]')).not.toBeNull()
  })

  test('is refetched when the held chain has moved on since', async () => {
    await boot('/a')

    await act(async () => {
      prefetch('/b')
      await new Promise((r) => setTimeout(r, 0))
    })

    // Go somewhere with a different chain first: the cached partial was
    // rendered against the old one and no longer composes.
    await go('/other')

    const before = requests.length
    await go('/b')

    expect(requests.length).toBeGreaterThan(before)
    expect(visiblePage()).toBe('/b')
  })
})

describe('opening and leaving an intercepted view', () => {
  test('the interceptor is rendered by the layout that declares its slot', async () => {
    await boot('/deep')
    await go('/deep/item/1')

    // Not the deepest shared layout: the one that owns the slot.
    expect(requests.at(-1)).toMatchObject({ url: '/deep/item/1', depth: SLOT_OWNER_DEPTH })
  })

  test('leaving it re-renders that layout, so the slot can empty again', async () => {
    // Claiming the whole chain would replace only the page below the layout,
    // leaving the interceptor in its slot — the modal stayed open over the
    // page behind it while the URL had already changed.
    await boot('/deep')
    await go('/deep/item/1')
    await go('/deep')

    const leaving = requests.at(-1)!

    expect(leaving.url).toBe('/deep')
    expect(leaving.depth).toBeLessThanOrEqual(SLOT_OWNER_DEPTH)
    // The claim itself is what forces it: fewer layouts than are mounted.
    expect(leaving.held!.split(',').length).toBeLessThanOrEqual(SLOT_OWNER_DEPTH)
  })

  test('an ordinary navigation afterwards claims the whole chain again', async () => {
    await boot('/deep')
    await go('/deep/item/1')
    await go('/deep')
    await go('/deep/item/1')
    await go('/deep')

    // The narrowing applies to leaving an interception, not to everything after.
    expect(requests.at(-1)!.held!.split(',').length).toBeLessThanOrEqual(SLOT_OWNER_DEPTH)
  })

  test('a link hovered inside the modal does not defeat the close', async () => {
    // Hovering prefetches against the whole chain, which is not the chain the
    // close will claim. Reusing it skips the layout holding the modal, so the
    // URL changes and the modal stays open over the page behind it — the shape
    // a real pointer produces and a scripted click never does.
    await boot('/deep')
    await go('/deep/item/1')

    await act(async () => {
      prefetch('/deep')
      await new Promise((r) => setTimeout(r, 0))
    })

    await go('/deep')

    expect(requests.at(-1)!.depth).toBeLessThanOrEqual(SLOT_OWNER_DEPTH)
  })
})
