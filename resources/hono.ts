// Hono binding for the RSC host.
//
// Thin on purpose. Everything real lives in ./host, which takes a Request and
// returns a Response — so the same code runs under Bun.serve, Deno, Workers
// and anything else built on fetch. This exists because Hono has a middleware
// signature, not because serving RSC needs a framework.
//
//   import { Hono } from 'hono'
//   import { rsc, assetsFrom } from 'rsc-router/hono'
//   import * as engine from './build/dist/rsc/index.js'
//
//   const app = new Hono()
//   app.use('*', rsc({ engine, assets: assetsFrom('./build/public') }))
//
// The engine carries the route table it was built with, so there is no
// manifest to pass and no way to pair a fresh bundle with a stale one.

import { createRscHandler } from './host.ts'
import type { RscHostOptions } from './host.ts'

/** Minimal shape of what Hono hands a middleware. Not imported, so hono is not a dependency. */
interface HonoContext {
  req: { raw: Request }
}

type Middleware = (c: HonoContext, next: () => Promise<void>) => Promise<Response | undefined>

/**
 * Serve the RSC app, falling through to the rest of the routes when the url is
 * not one of its pages.
 *
 * Middleware rather than a mounted router, so an app can keep its own API
 * routes: anything the manifest does not claim continues down the stack
 * instead of 404ing here.
 */
export function rsc(options: RscHostOptions): Middleware {
  const handle = createRscHandler(options)

  return async (c, next) => {
    const response = await handle(c.req.raw)

    if (response) return response

    await next()

    return undefined
  }
}

export { createRscHandler, assetsFrom, matchRoute, sharedDepth } from './host.ts'
export type { RscHostOptions, RscEngine, MatchedRoute } from './host.ts'
