'use client'

// Uses LaraBun's real Link so the fixture exercises the SPA engine
// (prefetch on hover + intercepted click -> window.__rsc_navigate).
import Link from '../../../resources/js/Link'

export function Nav() {
  return (
    <nav>
      <Link href="/" id="nav-home">Home</Link>
      {' | '}
      <Link href="/static" id="nav-static">Static</Link>
      {' | '}
      <Link href="/slow" id="nav-slow" prefetch="none">Slow</Link>
    </nav>
  )
}
