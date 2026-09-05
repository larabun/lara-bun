<?php

use LaravelRsc\Header;
use LaravelRsc\RscRedirectException;
use LaravelRsc\RuntimeBridge;

/**
 * A redirect decided during a render.
 *
 * The worker reports one on the start frame when the component that redirected
 * sat above every Suspense boundary — which is before PHP has written
 * anything, and therefore the only window in which the answer can be a status
 * code rather than a page.
 */
beforeEach(function () {
    $this->bridgeMock = Mockery::mock(RuntimeBridge::class);
    $this->app->instance(RuntimeBridge::class, $this->bridgeMock);
});

/** A render that reports a redirect the moment its start frame is read. */
function redirectingRender(string $to, int $status = 307): Closure
{
    return function () use ($to, $status) {
        return (function () use ($to, $status) {
            throw new RscRedirectException($to, $status);
            yield; // unreachable; makes this a generator
        })();
    };
}

test('a document is answered with a real status code and never sees the page', function () {
    $this->bridgeMock->shouldReceive('rscHtmlStream')->once()
        ->andReturnUsing(redirectingRender('/login'));

    Route::get('/guarded', fn () => rsc('Guarded'));

    $response = $this->get('/guarded');

    $response->assertStatus(307);
    expect($response->headers->get('Location'))->toBe('/login');
    expect($response->getContent())->toBe('');
});

test('the status the render asked for is the one sent', function () {
    $this->bridgeMock->shouldReceive('rscHtmlStream')->once()
        ->andReturnUsing(redirectingRender('/moved', 301));

    Route::get('/old', fn () => rsc('Old'));

    $this->get('/old')->assertStatus(301);
});

test('a navigation is answered with a header, never a 3xx', function () {
    // fetch() follows a 3xx transparently, so the client would receive the
    // destination's HTML where it expected a Flight payload and hand it to the
    // decoder — which reports its own confusion rather than the redirect.
    $this->bridgeMock->shouldReceive('rscStream')->once()
        ->andReturnUsing(redirectingRender('/login'));

    Route::get('/guarded', fn () => rsc('Guarded'));

    $response = $this->get('/guarded', [
        Header::X_RSC => '1',
        'Accept' => '*/*',
        Header::X_RSC_VERSION => '',
    ]);

    $response->assertStatus(204);
    expect($response->headers->get(Header::X_RSC_REDIRECT))->toBe('/login');
    expect($response->headers->get('Location'))->toBeNull();
});

test('a render that does not redirect is untouched', function () {
    $this->bridgeMock->shouldReceive('rscHtmlStream')->once()
        ->andReturnUsing(function () {
            return (function () {
                yield ['clientChunks' => []];
                yield '<p>fine</p>';
                yield ['rscPayload' => ''];
            })();
        });

    Route::get('/fine', fn () => rsc('Fine'));

    $response = $this->get('/fine');

    $response->assertStatus(200);
    expect($response->headers->get('Location'))->toBeNull();
    expect($response->headers->get(Header::X_RSC_REDIRECT))->toBeNull();
});
