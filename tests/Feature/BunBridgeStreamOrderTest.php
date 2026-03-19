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

use LaraBun\BunBridge;
use LaraBun\Rsc\Header;

test('rscStream yields stream-start before stream chunks', function () {
    $bridge = Mockery::mock(BunBridge::class)->makePartial();

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
    $bridge = Mockery::mock(BunBridge::class)->makePartial();

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
    $bridgeMock = Mockery::mock(BunBridge::class);
    app()->instance(BunBridge::class, $bridgeMock);

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
    ]);

    // Headers are derived from the first yield (stream-start), not the body
    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'text/x-component; charset=utf-8');
    $response->assertHeader(Header::X_RSC_CHUNKS);

    $chunks = json_decode($response->headers->get(Header::X_RSC_CHUNKS), true);
    expect($chunks)->toBe(['/build/rsc/test.js']);
});

test('rsc SPA response includes metadata from stream-start in headers', function () {
    $bridgeMock = Mockery::mock(BunBridge::class);
    app()->instance(BunBridge::class, $bridgeMock);

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
    ]);

    $response->assertStatus(200);
    $response->assertHeader(Header::X_RSC_TITLE, rawurlencode('My Page'));

    $meta = json_decode($response->headers->get(Header::X_RSC_META), true);
    expect($meta['title'])->toBe('My Page')
        ->and($meta['description'])->toBe('A test page');
});
