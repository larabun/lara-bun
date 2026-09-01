<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use LaraBun\Http\Middleware\ServeStaticRsc;
use LaraBun\Rsc\Header;
use LaraBun\Rsc\PrerenderService;

beforeEach(function () {
    $this->staticDir = sys_get_temp_dir().'/rsc-static-test-'.uniqid();
    mkdir($this->staticDir, 0755, true);
    Config::set('bun.rsc.static_path', $this->staticDir);

    Route::get('/test-page', fn () => 'dynamic content')
        ->middleware(ServeStaticRsc::class);

    Route::get('/nested/page', fn () => 'dynamic nested')
        ->middleware(ServeStaticRsc::class);
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->staticDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }

    rmdir($this->staticDir);
});

test('serves pre-rendered flight file on RSC request', function () {
    file_put_contents($this->staticDir.'/test-page.flight', 'flight-payload-data');
    file_put_contents($this->staticDir.'/test-page.meta.json', json_encode([
        'clientChunks' => ['/build/rsc/chunk-1.js'],
        'version' => 'abc123',
    ]));

    $response = $this->get('/test-page', [
        Header::X_RSC => 'true',
        'Accept' => '*/*',
    ]);

    $response->assertStatus(200)
        ->assertHeader(Header::X_RSC_VERSION, 'abc123');

    $contentType = $response->headers->get('Content-Type');
    expect($contentType)->toContain('text/x-component');

    expect($response->getContent())->toBe('flight-payload-data');
});

test('serves pre-rendered html file on non-RSC request', function () {
    file_put_contents($this->staticDir.'/test-page.html', '<html><body>Static Page</body></html>');

    $response = $this->get('/test-page');

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

    expect($response->getContent())->toBe('<html><body>Static Page</body></html>');
});

test('falls through when no static flight file exists for RSC request', function () {
    $response = $this->get('/test-page', [
        Header::X_RSC => 'true',
        'Accept' => '*/*',
    ]);

    $response->assertStatus(200);
    expect($response->getContent())->toBe('dynamic content');
});

test('falls through when no static html file exists for non-RSC request', function () {
    $response = $this->get('/test-page');

    $response->assertStatus(200);
    expect($response->getContent())->toBe('dynamic content');
});

test('static flight response carries only content type and version', function () {
    file_put_contents($this->staticDir.'/test-page.flight', 'payload');
    file_put_contents($this->staticDir.'/test-page.meta.json', json_encode([
        'version' => 'v1',
    ]));

    $response = $this->get('/test-page', [
        Header::X_RSC => 'true',
        'Accept' => '*/*',
    ]);

    // The prerendered Flight payload is self-describing under
    // @vitejs/plugin-rsc — no chunk, CSS or metadata sidecar headers.
    $response->assertStatus(200);
    expect($response->headers->get(Header::X_RSC_VERSION))->toBe('v1');

    foreach (['X-RSC-Chunks', 'X-RSC-CSS', 'X-RSC-Title', 'X-RSC-Meta'] as $header) {
        expect($response->headers->has($header))->toBeFalse("unexpected {$header} header");
    }
});

test('serves nested static pages', function () {
    mkdir($this->staticDir.'/nested', 0755, true);
    file_put_contents($this->staticDir.'/nested/page.flight', 'nested-flight');
    file_put_contents($this->staticDir.'/nested/page.meta.json', json_encode([
        'clientChunks' => [],
        'version' => 'v2',
    ]));

    $response = $this->get('/nested/page', [
        Header::X_RSC => 'true',
        'Accept' => '*/*',
    ]);

    $response->assertStatus(200);
    expect($response->getContent())->toBe('nested-flight');
});

test('requires both flight and meta files to serve static RSC', function () {
    // Only flight file, no meta - should fall through
    file_put_contents($this->staticDir.'/test-page.flight', 'payload');

    $response = $this->get('/test-page', [
        Header::X_RSC => 'true',
        'Accept' => '*/*',
    ]);

    expect($response->getContent())->toBe('dynamic content');
});

test('serves index for root path', function () {
    Route::get('/', fn () => 'dynamic root')
        ->middleware(ServeStaticRsc::class);

    file_put_contents($this->staticDir.'/index.html', '<html>Static Root</html>');

    $response = $this->get('/');

    $response->assertStatus(200);
    expect($response->getContent())->toBe('<html>Static Root</html>');
});

// ─── PPR shells ──────────────────────────────────────────────────────────────

test('serves the ppr shell when there is no fully static page', function () {
    file_put_contents($this->staticDir.'/test-page.ppr.html', '<html><body>shell<!--$?--></body></html>');

    $response = $this->get('/test-page');

    $response->assertStatus(200);
    expect($response->getContent())->toContain('shell');
});

test('marks the ppr shell cacheable by a CDN', function () {
    file_put_contents($this->staticDir.'/test-page.ppr.html', '<html><body>shell</body></html>');
    Config::set('bun.rsc.shell_ttl', 600);
    Config::set('bun.rsc.shell_stale_while_revalidate', 1200);

    $cacheControl = $this->get('/test-page')->headers->get('Cache-Control');

    // The shell holds no request data, so a shared cache may serve it.
    expect($cacheControl)->toContain('public')
        ->and($cacheControl)->toContain('s-maxage=600')
        ->and($cacheControl)->toContain('stale-while-revalidate=1200');
});

test('prefers a fully static page over a shell', function () {
    file_put_contents($this->staticDir.'/test-page.html', '<html><body>full page</body></html>');
    file_put_contents($this->staticDir.'/test-page.ppr.html', '<html><body>shell</body></html>');

    $content = $this->get('/test-page')->getContent();

    expect($content)->toContain('full page')
        ->and($content)->not->toContain('shell');
});

test('does not mark a fully static page as a cacheable shell', function () {
    file_put_contents($this->staticDir.'/test-page.html', '<html><body>full page</body></html>');

    // Only shells carry the shared-cache directive.
    expect($this->get('/test-page')->headers->get('Cache-Control'))->not->toContain('s-maxage');
});

test('falls through to dynamic rendering when neither artifact exists', function () {
    expect($this->get('/test-page')->getContent())->toBe('dynamic content');
});

test('serves an etag and honours a matching If-None-Match', function () {
    file_put_contents($this->staticDir.'/test-page.ppr.html', '<html><body>shell</body></html>');

    $etag = $this->get('/test-page')->headers->get('ETag');

    expect($etag)->not->toBeEmpty();

    $this->get('/test-page', ['If-None-Match' => $etag])->assertStatus(304);
});

test('keeps a nonce-bearing shell out of shared caches', function () {
    // The nonce is baked into the body, so one cached copy would hand every
    // visitor the same nonce and defeat the policy.
    app()->instance('csp-nonce', 'test-nonce-123');
    file_put_contents(
        $this->staticDir.'/test-page.ppr.html',
        '<html><body><script nonce="'.PrerenderService::NONCE_PLACEHOLDER.'"></script></body></html>',
    );

    $response = $this->get('/test-page');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->toContain('test-nonce-123');
});
