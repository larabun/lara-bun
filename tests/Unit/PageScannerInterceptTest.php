<?php

use LaraBun\Rsc\PageScanner;

beforeEach(function () {
    $this->appDir = sys_get_temp_dir().'/rsc-intercept-test-'.uniqid();
    mkdir($this->appDir, 0755, true);
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->appDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }

    rmdir($this->appDir);
});

function createInterceptFile(string $base, string $path): void
{
    $full = $base.'/'.$path;
    $dir = dirname($full);

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($full, '// test file');
}

test('intercept pages are excluded from main page list', function () {
    createInterceptFile($this->appDir, 'page.tsx');
    createInterceptFile($this->appDir, '@modal/(.)photo/[id]/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    // Only the root page should be in the list, not the intercept page
    expect($scanner->getPages())->toHaveCount(1);
    expect($scanner->getPages()[0]->urlPattern)->toBe('/');
});

test('same-level intercept (.) matches target page', function () {
    createInterceptFile($this->appDir, 'page.tsx');
    createInterceptFile($this->appDir, 'photo/[id]/page.tsx');
    createInterceptFile($this->appDir, '@modal/(.)photo/[id]/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    $photoPage = collect($scanner->getPages())->first(fn ($p) => $p->urlPattern === 'photo/{id}');

    expect($photoPage)->not->toBeNull()
        ->and($photoPage->interceptRoutes)->toHaveCount(1)
        ->and($photoPage->interceptRoutes[0]['slot'])->toBe('modal')
        ->and($photoPage->interceptRoutes[0]['component'])->toBe('app/@modal/(.)photo/[id]/page');
});

test('one-level-up intercept (..) calculates correct URL', function () {
    createInterceptFile($this->appDir, 'page.tsx');
    createInterceptFile($this->appDir, 'feed/page.tsx');
    createInterceptFile($this->appDir, 'photo/[id]/page.tsx');
    createInterceptFile($this->appDir, 'feed/@modal/(..)photo/[id]/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    $photoPage = collect($scanner->getPages())->first(fn ($p) => $p->urlPattern === 'photo/{id}');

    expect($photoPage)->not->toBeNull()
        ->and($photoPage->interceptRoutes)->toHaveCount(1)
        ->and($photoPage->interceptRoutes[0]['slot'])->toBe('modal');
});

test('root-level intercept (...) matches from app root', function () {
    createInterceptFile($this->appDir, 'page.tsx');
    createInterceptFile($this->appDir, 'photo/[id]/page.tsx');
    createInterceptFile($this->appDir, 'dashboard/settings/@drawer/(...)photo/[id]/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    $photoPage = collect($scanner->getPages())->first(fn ($p) => $p->urlPattern === 'photo/{id}');

    expect($photoPage)->not->toBeNull()
        ->and($photoPage->interceptRoutes)->toHaveCount(1)
        ->and($photoPage->interceptRoutes[0]['slot'])->toBe('drawer');
});

test('multiple intercepts for same target page', function () {
    createInterceptFile($this->appDir, 'page.tsx');
    createInterceptFile($this->appDir, 'photo/[id]/page.tsx');
    createInterceptFile($this->appDir, '@modal/(.)photo/[id]/page.tsx');
    createInterceptFile($this->appDir, '@drawer/(.)photo/[id]/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    $photoPage = collect($scanner->getPages())->first(fn ($p) => $p->urlPattern === 'photo/{id}');

    expect($photoPage)->not->toBeNull()
        ->and($photoPage->interceptRoutes)->toHaveCount(2);

    $slots = array_column($photoPage->interceptRoutes, 'slot');
    sort($slots);

    expect($slots)->toBe(['drawer', 'modal']);
});

test('page without intercepts has empty interceptRoutes', function () {
    createInterceptFile($this->appDir, 'page.tsx');
    createInterceptFile($this->appDir, 'about/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    $aboutPage = collect($scanner->getPages())->first(fn ($p) => $p->urlPattern === 'about');

    expect($aboutPage->interceptRoutes)->toBe([]);
});

test('deeply nested intercept pages are excluded', function () {
    createInterceptFile($this->appDir, 'page.tsx');
    createInterceptFile($this->appDir, '@modal/(.)photo/[id]/details/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    // Only the root page, not the intercept subpage
    expect($scanner->getPages())->toHaveCount(1);
});
