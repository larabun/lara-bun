<?php

/**
 * The client router decides whether a click is an interception before it asks
 * the server, so it needs these patterns baked into its bundle.
 *
 * PageScanner's own tests cover the (.)/(..)/(...) convention. What was missing
 * — and what let interception break silently after the Vite migration — is any
 * test of the handoff: nothing checked that the patterns PHP discovers ever
 * reach the client in the shape its matcher expects.
 */

use LaravelRsc\Support\InterceptManifest;

function interceptAppDir(array $files): string
{
    $dir = sys_get_temp_dir().'/rsc-intercept-'.uniqid();

    foreach ($files as $relative) {
        $full = $dir.'/'.$relative;
        @mkdir(dirname($full), 0755, true);
        file_put_contents($full, "export default function Page() { return null }\n");
    }

    return $dir;
}

function removeDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

test('an intercepted route reaches the client as the pattern its matcher expects', function () {
    // The client builds a regex by replacing [param]; a Laravel-style {param}
    // would never match, so the shape here is part of the contract.
    $dir = interceptAppDir([
        'gallery/page.tsx',
        'gallery/item/[id]/page.tsx',
        'gallery/@modal/(.)item/[id]/page.tsx',
    ]);

    expect(InterceptManifest::discover($dir))->toBe([
        ['urlPattern' => '/gallery/item/[id]', 'slot' => 'modal'],
    ]);

    removeDir($dir);
});

test('a catch-all intercept keeps its [...param] form', function () {
    // The Laravel route pattern compiles both [id] and [...slug] to {param}.
    // Deriving from that would match a catch-all as a single segment, sending
    // the wrong routes to the modal — so the pattern comes from the file path.
    $dir = interceptAppDir([
        'docs/page.tsx',
        'docs/[...slug]/page.tsx',
        'docs/@modal/(.)[...slug]/page.tsx',
    ]);

    expect(InterceptManifest::discover($dir)[0]['urlPattern'])->toBe('/docs/[...slug]');

    removeDir($dir);
});

test('a tree with no interceptors publishes nothing', function () {
    $dir = interceptAppDir(['page.tsx', 'about/page.tsx']);

    expect(InterceptManifest::discover($dir))->toBe([]);

    removeDir($dir);
});

test('a missing app directory is empty rather than fatal', function () {
    expect(InterceptManifest::discover('/nonexistent/rsc/app'))->toBe([]);
});

test('route groups and slot directories contribute no URL segment', function () {
    // Input is always the intercepted page's path, never the interceptor's.
    expect(InterceptManifest::clientUrlPattern('app/(marketing)/pricing/page'))->toBe('/pricing')
        ->and(InterceptManifest::clientUrlPattern('app/shop/@modal/default'))->toBe('/shop/default')
        ->and(InterceptManifest::clientUrlPattern('app/shop/item/[id]/page'))->toBe('/shop/item/[id]')
        ->and(InterceptManifest::clientUrlPattern('app/page'))->toBe('/');
});
