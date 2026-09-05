<?php

use RscKit\RuntimeBridge;

beforeEach(function () {
    $this->bridgeMock = Mockery::mock(RuntimeBridge::class);
    $this->app->instance(RuntimeBridge::class, $this->bridgeMock);
});

// NOTE: these streamed responses flush all output buffers (the streaming
// pattern), which conflicts with TestResponse::streamedContent(), so — like the
// other streamed-response tests here — we assert status/headers + that the
// bridge was invoked. The vite path streaming the worker's complete HTML
// document (vs the bun Blade shell) is proven directly in the engine build/
// render tests.

test('vite engine renders initial load via rscHtmlStream and returns streaming HTML', function () {
    config()->set('rsc.engine', 'vite');

    $this->bridgeMock
        ->shouldReceive('rscHtmlStream')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                yield ['clientChunks' => [], 'metadata' => null];
                yield '<!DOCTYPE html><html><head><link rel="stylesheet" href="/assets/app.css"></head><body><main><h1>Vite page</h1></main>';
                yield '<script id="_R_">import("/assets/index.js")</script></body></html>';
                yield ['rscPayload' => 'FLIGHT'];
            })();
        });

    Route::get('/vite-page', fn () => rsc('app/page')->layout('app/layout'));

    $this->get('/vite-page')
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'text/html; charset=utf-8');
});

test('bun engine still renders through the blade shell path (unchanged default)', function () {
    config()->set('rsc.engine', 'bun');

    $this->bridgeMock
        ->shouldReceive('rscHtmlStream')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                yield ['clientChunks' => []];
                yield '<main><h1>Bun page</h1></main>';
                yield ['rscPayload' => ''];
            })();
        });

    Route::get('/bun-page', fn () => rsc('app/page')->layout('app/layout'));

    $this->get('/bun-page')->assertStatus(200);
});
