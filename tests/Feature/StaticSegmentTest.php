<?php

/**
 * A prerendered route has to answer partially too.
 *
 * Serving the whole document to a client that already has the layouts makes it
 * replace the root, and replacing the root unmounts the pages retained behind
 * it — so the form you were filling in does not survive going back. Most
 * routes in a real app are prerendered, so this is the common path, not an
 * edge case.
 */

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use RscKit\Header;
use RscKit\Http\Middleware\ServeStaticRsc;

beforeEach(function () {
    $this->staticDir = sys_get_temp_dir().'/rsc-segment-'.uniqid();
    File::ensureDirectoryExists($this->staticDir.'/docs');
    Config::set('rsc.static_path', $this->staticDir);

    Route::get('/docs/validation', fn () => 'live render')
        ->middleware(ServeStaticRsc::class);

    File::put($this->staticDir.'/docs/validation.flight', 'FULL-DOCUMENT-PAYLOAD');
    File::put($this->staticDir.'/docs/validation.seg1.flight', 'FROM-DEPTH-1');
    File::put($this->staticDir.'/docs/validation.seg2.flight', 'FROM-DEPTH-2');
    File::put($this->staticDir.'/docs/validation.meta.json', json_encode([
        'clientChunks' => [],
        'version' => 'v1',
        'layouts' => ['app/layout', 'app/docs/layout'],
    ]));
});

afterEach(fn () => File::deleteDirectory($this->staticDir));

test('a client holding the whole chain gets the page alone', function () {
    $response = $this->get('/docs/validation', [
        Header::X_RSC => '1',
        Header::X_RSC_SEGMENTS => 'app/layout,app/docs/layout',
    ]);

    expect($response->getContent())->toBe('FROM-DEPTH-2')
        ->and($response->headers->get(Header::X_RSC_SEGMENT_DEPTH))->toBe('2');
});

test('a client sharing only part of the chain gets the variant for that depth', function () {
    // Arriving from a section with its own layout: the root is shared, the
    // rest is not. One variant per depth is why this can be partial at all.
    $response = $this->get('/docs/validation', [
        Header::X_RSC => '1',
        Header::X_RSC_SEGMENTS => 'app/layout,app/blog/layout',
    ]);

    expect($response->getContent())->toBe('FROM-DEPTH-1')
        ->and($response->headers->get(Header::X_RSC_SEGMENT_DEPTH))->toBe('1');
});

test('a hard load with no chain gets the whole document', function () {
    $response = $this->get('/docs/validation', [Header::X_RSC => '1']);

    expect($response->getContent())->toBe('FULL-DOCUMENT-PAYLOAD')
        ->and($response->headers->get(Header::X_RSC_SEGMENT_DEPTH))->toBe('0');
});

test('a client sharing no layouts gets the whole document', function () {
    $response = $this->get('/docs/validation', [
        Header::X_RSC => '1',
        Header::X_RSC_SEGMENTS => 'app/other/layout',
    ]);

    expect($response->getContent())->toBe('FULL-DOCUMENT-PAYLOAD')
        ->and($response->headers->get(Header::X_RSC_SEGMENT_DEPTH))->toBe('0');
});

test('always reports the chain, so the client knows what it now holds', function () {
    $response = $this->get('/docs/validation', [Header::X_RSC => '1']);

    expect($response->headers->get(Header::X_RSC_LAYOUTS))->toBe('app/layout,app/docs/layout');
});

test('falls back to the whole document when no segment file was prerendered', function () {
    // An older build, or a route whose segment render failed.
    File::delete($this->staticDir.'/docs/validation.seg2.flight');

    $response = $this->get('/docs/validation', [
        Header::X_RSC => '1',
        Header::X_RSC_SEGMENTS => 'app/layout,app/docs/layout',
    ]);

    expect($response->getContent())->toBe('FULL-DOCUMENT-PAYLOAD')
        ->and($response->headers->get(Header::X_RSC_SEGMENT_DEPTH))->toBe('0');
});
