<?php

// Covers the typed-routes pipeline: rsc:route-manifest is what the build reads
// to generate routes.generated.ts, so its JSON shape is the contract.

use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->sourceDir = sys_get_temp_dir().'/rsc-manifest-'.uniqid();
    mkdir($this->sourceDir.'/app', 0755, true);
    config()->set('rsc.source_dir', $this->sourceDir);
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }

    rmdir($this->sourceDir);
});

function writeRouteFile(string $base, string $path, string $contents = '// test file'): void
{
    $full = $base.'/'.$path;

    if (! is_dir(dirname($full))) {
        mkdir(dirname($full), 0755, true);
    }

    file_put_contents($full, $contents);
}

function manifest(): array
{
    Artisan::call('rsc:route-manifest');

    return json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
}

test('returns an empty manifest when there is no app directory', function () {
    config()->set('rsc.source_dir', $this->sourceDir.'/missing');

    expect(manifest())->toBe([]);
});

test('emits url patterns for static and dynamic routes', function () {
    writeRouteFile($this->sourceDir, 'app/page.tsx');
    writeRouteFile($this->sourceDir, 'app/docs/page.tsx');
    writeRouteFile($this->sourceDir, 'app/docs/[slug]/page.tsx');

    $patterns = array_column(manifest(), 'urlPattern');

    expect($patterns)->toContain('/')
        ->and($patterns)->toContain('/docs')
        ->and($patterns)->toContain('/docs/{slug}');
});

test('includes staticPaths from a route.php simple list', function () {
    writeRouteFile($this->sourceDir, 'app/docs/[slug]/page.tsx');
    writeRouteFile(
        $this->sourceDir,
        'app/docs/[slug]/route.php',
        "<?php\n\nreturn LaravelRsc\\PageRoute::make()->staticPaths(['installation', 'configuration']);\n",
    );

    $entry = collect(manifest())->firstWhere('urlPattern', '/docs/{slug}');

    // A flat list has no param name in it, so it is grouped under _default.
    expect($entry['staticPaths']['_default'])->toBe(['installation', 'configuration']);
});

test('groups and deduplicates staticPaths given multi-param combinations', function () {
    writeRouteFile($this->sourceDir, 'app/posts/[year]/[slug]/page.tsx');
    writeRouteFile(
        $this->sourceDir,
        'app/posts/[year]/[slug]/route.php',
        "<?php\n\nreturn LaravelRsc\\PageRoute::make()->staticPaths([\n".
        "    ['year' => '2026', 'slug' => 'a'],\n".
        "    ['year' => '2026', 'slug' => 'b'],\n".
        "]);\n",
    );

    $entry = collect(manifest())->firstWhere('urlPattern', '/posts/{year}/{slug}');

    expect($entry['staticPaths']['year'])->toBe(['2026'])
        ->and($entry['staticPaths']['slug'])->toBe(['a', 'b']);
});

test('extracts literal alternations from where constraints', function () {
    writeRouteFile($this->sourceDir, 'app/docs/[slug]/page.tsx');
    writeRouteFile(
        $this->sourceDir,
        'app/docs/[slug]/route.php',
        "<?php\n\nreturn LaravelRsc\\PageRoute::make()->where('slug', 'alpha|beta');\n",
    );

    $entry = collect(manifest())->firstWhere('urlPattern', '/docs/{slug}');

    expect($entry['where']['slug'])->toBe(['alpha', 'beta']);
});

test('skips where constraints that are not simple alternations', function () {
    writeRouteFile($this->sourceDir, 'app/docs/[slug]/page.tsx');
    writeRouteFile(
        $this->sourceDir,
        'app/docs/[slug]/route.php',
        "<?php\n\nreturn LaravelRsc\\PageRoute::make()->where('slug', '[0-9]+');\n",
    );

    $entry = collect(manifest())->firstWhere('urlPattern', '/docs/{slug}');

    // The key may still be present but must offer no literals to generate from.
    expect($entry['where'] ?? [])->not->toHaveKey('slug');
});

test('reports intercept routes against the page they intercept', function () {
    writeRouteFile($this->sourceDir, 'app/feed/page.tsx');
    writeRouteFile($this->sourceDir, 'app/photo/[id]/page.tsx');
    writeRouteFile($this->sourceDir, 'app/@modal/(.)photo/[id]/page.tsx');

    $entry = collect(manifest())->firstWhere('urlPattern', '/photo/{id}');

    expect($entry['intercepts'][0]['slot'])->toBe('modal')
        ->and($entry['intercepts'][0]['component'])->toBe('app/@modal/(.)photo/[id]/page');
});
