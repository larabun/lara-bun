<?php

namespace LaravelRsc;

use Symfony\Component\HttpFoundation\Response;

/**
 * Headers the render asked to put on its own response.
 *
 * Middleware runs before PHP has written a status line, so a header or a
 * cookie set there still has an answer to go on. They arrive from the worker
 * as a list of pairs rather than a map because Set-Cookie repeats — several
 * cookies are several headers, and a map would keep only the last.
 *
 * Cookies set here are encrypted by Laravel exactly like every other cookie.
 * An earlier version registered each one with EncryptCookies::except() on the
 * grounds that the engine owns the format of what it writes, which was wrong
 * twice over. Laravel's cookie encryption is authenticated, so it is not only
 * privacy but integrity: an exempted cookie can be *forged* by the client, and
 * an app that trusts what it reads back has an authorization bug rather than a
 * disclosure one. And the exemption was static, so a name exempted once stayed
 * exempted for the life of the process — under Octane, for every later
 * request, including for a cookie the application set with the same name.
 *
 * The engine reads them back as plaintext because RuntimeBridge forwards
 * Laravel's decrypted jar rather than the raw Cookie header. An app that needs
 * a cookie readable by browser JavaScript adds its name to the app's own
 * EncryptCookies::$except, which is where that decision already lives.
 */
final class ResponseHeaders
{
    /**
     * @param  list<array{0: string, 1: string}>  $headers
     */
    public static function applyTo(Response $response, array $headers): Response
    {
        foreach ($headers as [$name, $value]) {
            // Appended, never replaced: the host chose its own headers
            // deliberately, and a cookie must not overwrite the one before it.
            $response->headers->set($name, $value, replace: false);
        }

        return $response;
    }
}
