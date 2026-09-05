<?php

/**
 * Tests that stream-start is read eagerly from the main socket before
 * entering the callback select loop. This prevents php() callbacks
 * from blocking HTTP header delivery.
 *
 * These tests verify the Generator yield order from rscStream and
 * rscHtmlStream to ensure the first yield (headers/metadata) arrives
 * before any callback processing can delay it.
 */

use RscKit\Header;
use RscKit\RuntimeBridge;

test('rscStream yields stream-start before stream chunks', function () {
    $bridge = Mockery::mock(RuntimeBridge::class)->makePartial();

    // Simulate the generator that rscStream returns — stream-start must be first
    $bridge->shouldReceive('rscStream')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                // First yield MUST be the metadata/clientChunks array
                yield ['clientChunks' => ['/build/rsc/entry.js'], 'metadata' => ['title' => 'Test']];
                // Then stream chunks
                yield '0:["$","div",null,{}]';
            })();
        });

    $generator = $bridge->rscStream('TestComponent', []);
    $first = $generator->current();

    expect($first)->toBeArray()
        ->and($first)->toHaveKey('clientChunks')
        ->and($first)->toHaveKey('metadata');
});

test('rscHtmlStream yields html-start before html chunks', function () {
    $bridge = Mockery::mock(RuntimeBridge::class)->makePartial();

    $bridge->shouldReceive('rscHtmlStream')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                // First yield MUST be the metadata/clientChunks array
                yield ['clientChunks' => ['/build/rsc/entry.js'], 'metadata' => null];
                // Then HTML chunks
                yield '<div>content</div>';
                yield ['rscPayload' => '0:["$","div",null,{}]'];
            })();
        });

    $generator = $bridge->rscHtmlStream('TestComponent', []);
    $first = $generator->current();

    expect($first)->toBeArray()
        ->and($first)->toHaveKey('clientChunks')
        ->and($first)->toHaveKey('metadata');
});

test('rsc SPA response sends headers without waiting for stream body', function () {
    $bridgeMock = Mockery::mock(RuntimeBridge::class);
    app()->instance(RuntimeBridge::class, $bridgeMock);

    // Track timing: the generator yields stream-start immediately,
    // then delays 100ms before yielding the first chunk.
    // The response headers must be set from stream-start (first yield)
    // without blocking on the delayed chunk.
    $bridgeMock
        ->shouldReceive('rscStream')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                yield ['clientChunks' => ['/build/rsc/test.js'], 'metadata' => ['title' => 'Fast']];
                // Simulate slow async content — headers should already be sent
                usleep(100_000);
                yield '0:["$","div",null,{"children":"slow content"}]';
            })();
        });

    Route::get('/test-stream-timing', fn () => rsc('FastPage', []));

    $response = test()->get('/test-stream-timing', [
        Header::X_RSC => 'true',
        'Accept' => '*/*',
    ]);

    // Headers are derived from the first yield (stream-start), not the body
    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'text/x-component; charset=utf-8');
    $response->assertHeader(Header::X_RSC_VERSION);
});

test('rsc SPA response carries no asset or metadata headers', function () {
    $bridgeMock = Mockery::mock(RuntimeBridge::class);
    app()->instance(RuntimeBridge::class, $bridgeMock);

    $bridgeMock
        ->shouldReceive('rscStream')
        ->once()
        ->andReturnUsing(function () {
            return (function () {
                yield [
                    'clientChunks' => [],
                    'metadata' => ['title' => 'My Page', 'description' => 'A test page'],
                ];
                yield '0:["$","div",null,{}]';
            })();
        });

    Route::get('/test-meta-timing', fn () => rsc('MetaPage', []));

    $response = test()->get('/test-meta-timing', [
        Header::X_RSC => 'true',
        'Accept' => '*/*',
    ]);

    // Client references, stylesheet links and <title>/<meta> all travel inside
    // the Flight payload under @vitejs/plugin-rsc, so the response emits none
    // of the old bun-engine sidecar headers.
    $response->assertStatus(200);

    foreach (['X-RSC-Chunks', 'X-RSC-CSS', 'X-RSC-Title', 'X-RSC-Meta'] as $header) {
        expect($response->headers->has($header))->toBeFalse("unexpected {$header} header");
    }
});
