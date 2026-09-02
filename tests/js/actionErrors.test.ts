/**
 * What a failed server action gives back.
 *
 * The response is JSON or a redirect header, never a Flight stream. Handing
 * one to the Flight decoder does not surface the server's message — it
 * surfaces the decoder's own confusion, which is what reached applications:
 * "enqueueModel is not a function" for a refusal that had a perfectly good
 * message attached, and "Connection closed." for a validation failure whose
 * field errors had arrived intact.
 */

import { describe, expect, test } from 'bun:test'
import {
  ServerRedirectError,
  ServerValidationError,
  throwForFailedAction,
} from '../../resources/js/errors.ts'

const json = (status: number, body: unknown, headers: Record<string, string> = {}) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  })

describe('a failed action response', () => {
  test('a stream is left alone to be decoded', async () => {
    await expect(throwForFailedAction(new Response('flight', { status: 200 }))).resolves.toBeUndefined()
  })

  test('a validation failure keeps its message and field errors', async () => {
    const response = json(422, {
      message: 'Everyone asks for socks.',
      errors: { title: ['Everyone asks for socks.'] },
    })

    const err = await throwForFailedAction(response).catch((e) => e)

    expect(err).toBeInstanceOf(ServerValidationError)
    expect((err as ServerValidationError).message).toBe('Everyone asks for socks.')
    expect((err as ServerValidationError).errors).toEqual({ title: ['Everyone asks for socks.'] })
  })

  test('a redirect is a location, whatever the status', async () => {
    // An expired session answers this way. The destination is the login page,
    // not a message for a form to display.
    const response = new Response('', { status: 401, headers: { 'X-RSC-Redirect': '/login' } })

    const err = await throwForFailedAction(response).catch((e) => e)

    expect(err).toBeInstanceOf(ServerRedirectError)
    expect((err as ServerRedirectError).location).toBe('/login')
  })

  test('a redirect wins over the status it arrived with', async () => {
    const response = json(422, { message: 'ignored' }, { 'X-RSC-Redirect': '/login' })

    expect(await throwForFailedAction(response).catch((e) => e)).toBeInstanceOf(ServerRedirectError)
  })

  test('anything else says what the status was', async () => {
    const err = await throwForFailedAction(new Response('', { status: 500 })).catch((e) => e)

    expect((err as Error).message).toContain('500')
  })

  test('a 422 with no body still produces a validation error', async () => {
    // Never let a malformed body turn a refusal into a parse crash.
    const err = await throwForFailedAction(new Response('not json', { status: 422 })).catch((e) => e)

    expect(err).toBeInstanceOf(ServerValidationError)
    expect((err as ServerValidationError).errors).toEqual({})
  })
})
