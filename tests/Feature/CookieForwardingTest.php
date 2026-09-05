<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use RscKit\ResponseHeaders;
use RscKit\RuntimeBridge;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the engine sees when it asks for a cookie.
 *
 * Laravel encrypts cookies on the way out and decrypts them on the way in, so
 * a request carries ciphertext in its Cookie header while `$request->cookies`
 * holds plaintext. Forwarding the header — the obvious thing, and what this
 * used to do — meant `cookies().get()` in a server component returned an
 * encrypted blob for every cookie the app had set, and the engine could not
 * read back what it had itself written on the previous response.
 *
 * The two are set apart deliberately here rather than run through
 * EncryptCookies: that middleware leaves a value it cannot decrypt exactly as
 * it found it, so a test that merely fails to decrypt looks identical to one
 * that never ran the middleware at all.
 */
function envelopeFor(Request $request): array
{
    app()->instance('request', $request);

    $method = new ReflectionMethod(RuntimeBridge::class, 'requestEnvelope');
    $method->setAccessible(true);

    return $method->invoke(app(RuntimeBridge::class));
}

test('the engine is given the decrypted jar, not the raw header', function () {
    $request = Request::create('/docs', 'GET', server: ['HTTP_COOKIE' => 'locale=ENCRYPTED-BLOB']);
    $request->cookies->set('locale', 'fr');

    $headers = envelopeFor($request)['headers'];

    expect($headers['cookie'])->toBe('locale=fr')
        ->and($headers['cookie'])->not->toContain('ENCRYPTED-BLOB');
});

test('several cookies all arrive, encoded so a value cannot invent one', function () {
    $request = Request::create('/docs', 'GET');
    $request->cookies->set('locale', 'fr');
    $request->cookies->set('next', '/a; admin=yes');

    $cookie = envelopeFor($request)['headers']['cookie'];

    expect($cookie)->toContain('locale=fr')
        ->and($cookie)->toContain('next=%2Fa%3B%20admin%3Dyes')
        // The smuggled pair must not survive as a pair of its own.
        ->and($cookie)->not->toContain('; admin=yes');
});

test('a request with no cookies forwards no cookie header at all', function () {
    // Rather than an empty one, which some readers parse as a cookie with an
    // empty name — a difference nobody would think to look for.
    $headers = envelopeFor(Request::create('/docs', 'GET'))['headers'];

    expect($headers)->not->toHaveKey('cookie');
});

test('a cookie the engine sets is left for Laravel to encrypt', function () {
    // The property that matters is integrity rather than privacy: Laravel's
    // cookie encryption is authenticated, so a cookie exempted from it can be
    // forged by whoever holds it, and an app that trusts what it reads back
    // has an authorization bug. An earlier version registered every engine-set
    // cookie with EncryptCookies::except(), by name, process-wide.
    $response = ResponseHeaders::applyTo(new Response, [['Set-Cookie', 'session=plain; Path=/']]);

    expect($response->headers->get('set-cookie'))->toContain('session=plain');

    // Nothing was added to the exemption list on the way through.
    $except = new ReflectionProperty(EncryptCookies::class, 'neverEncrypt');
    $except->setAccessible(true);

    expect($except->getValue())->not->toContain('session');
});
