<?php

// Covers the typed-routes pipeline: rsc:route-manifest is what the build reads
// to generate routes.generated.ts, so its JSON shape is the contract.

use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    // Under the project root, because the build writes route.php paths
    // relative to it — a fixture outside it could not be addressed at all.
    $this->sourceDir = base_path('rsc-manifest-'.uniqid());
    mkdir($this->sourceDir.'/app', 0755, true);

});

afterEach(function () {
    // Before the next test boots: route registration reads this at boot, so a
    // manifest left behind would point the next app at deleted fixtures.
    $manifest = base_path('bootstrap/rsc/vite/routes.json');

    if (is_file($manifest)) {
        unlink($manifest);
    }

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

/**
 * Stand in for the build.
 *
 * Discovering the route tree is the plugin's job now, so a test that wants
 * routes declares them rather than writing files and expecting a scan. That
 * includes finding route.php: the build stats for it while it is already
 * walking those directories, and writes down what it found. So this stands in
 * for that too, and has to run after the files it reports exist.
 *
 * @param  list<string>  $components
 */
function writeManifest(array $components, string $base): void
{
    $routes = [];

    foreach ($components as $component) {
        $parts = explode('/', $component);
        $segments = [];

        // Everything between app/ and /page, in the manifest's own terms.
        foreach (array_slice($parts, 1, -1) as $part) {
            if (str_starts_with($part, '(') && str_ends_with($part, ')')) {
                continue;
            }

            $segments[] = str_starts_with($part, '[')
                ? ['type' => 'param', 'value' => trim($part, '[]')]
                : ['type' => 'static', 'value' => $part];
        }

        // Root-relative, as the build writes them.
        $dir = implode('/', array_slice($parts, 0, -1));
        $configOf = function (string $relative) use ($base): ?string {
            $path = rtrim($base.'/'.$relative, '/').'/route.php';

            return is_file($path) ? ltrim(str_replace(base_path(), '', $path), '/') : null;
        };

        $ancestors = [];
        $walk = array_slice(explode('/', $dir), 0, -1);

        while ($walk !== []) {
            if ($found = $configOf(implode('/', $walk))) {
                array_unshift($ancestors, $found);
            }

            array_pop($walk);
        }

        $routes[] = [
            'component' => $component,
            'segments' => $segments,
            'layouts' => [],
            'loadings' => [],
            'slots' => [],
            'sections' => [],
            'config' => $configOf($dir),
            'ancestorConfigs' => $ancestors,
        ];
    }

    $dir = base_path('bootstrap/rsc/vite');

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($dir.'/routes.json', json_encode([
        'version' => 1,
        'routes' => $routes,
        'intercepts' => [],
    ], JSON_THROW_ON_ERROR));
}

function manifest(): array
{
    Artisan::call('rsc:route-manifest');

    return json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
}

test('returns an empty manifest when there is no build to read', function () {
    expect(manifest())->toBe([]);
});

test('emits url patterns for static and dynamic routes', function () {
    writeRouteFile($this->sourceDir, 'app/page.tsx');
    writeRouteFile($this->sourceDir, 'app/docs/page.tsx');
    writeRouteFile($this->sourceDir, 'app/docs/[slug]/page.tsx');
    writeManifest(['app/page', 'app/docs/page', 'app/docs/[slug]/page'], $this->sourceDir);

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
        "<?php\n\nreturn RscKit\\PageRoute::make()->staticPaths(['installation', 'configuration']);\n",
    );
    writeManifest(['app/docs/[slug]/page'], $this->sourceDir);

    $entry = collect(manifest())->firstWhere('urlPattern', '/docs/{slug}');

    // A flat list has no param name in it, so it is grouped under _default.
    expect($entry['staticPaths']['_default'])->toBe(['installation', 'configuration']);
});

test('groups and deduplicates staticPaths given multi-param combinations', function () {
    writeRouteFile($this->sourceDir, 'app/posts/[year]/[slug]/page.tsx');
    writeRouteFile(
        $this->sourceDir,
        'app/posts/[year]/[slug]/route.php',
        "<?php\n\nreturn RscKit\\PageRoute::make()->staticPaths([\n".
        "    ['year' => '2026', 'slug' => 'a'],\n".
        "    ['year' => '2026', 'slug' => 'b'],\n".
        "]);\n",
    );
    writeManifest(['app/posts/[year]/[slug]/page'], $this->sourceDir);

    $entry = collect(manifest())->firstWhere('urlPattern', '/posts/{year}/{slug}');

    expect($entry['staticPaths']['year'])->toBe(['2026'])
        ->and($entry['staticPaths']['slug'])->toBe(['a', 'b']);
});

test('extracts literal alternations from where constraints', function () {
    writeRouteFile($this->sourceDir, 'app/docs/[slug]/page.tsx');
    writeRouteFile(
        $this->sourceDir,
        'app/docs/[slug]/route.php',
        "<?php\n\nreturn RscKit\\PageRoute::make()->where('slug', 'alpha|beta');\n",
    );
    writeManifest(['app/docs/[slug]/page'], $this->sourceDir);

    $entry = collect(manifest())->firstWhere('urlPattern', '/docs/{slug}');

    expect($entry['where']['slug'])->toBe(['alpha', 'beta']);
});

test('skips where constraints that are not simple alternations', function () {
    writeRouteFile($this->sourceDir, 'app/docs/[slug]/page.tsx');
    writeRouteFile(
        $this->sourceDir,
        'app/docs/[slug]/route.php',
        "<?php\n\nreturn RscKit\\PageRoute::make()->where('slug', '[0-9]+');\n",
    );
    writeManifest(['app/docs/[slug]/page'], $this->sourceDir);

    $entry = collect(manifest())->firstWhere('urlPattern', '/docs/{slug}');

    // The key may still be present but must offer no literals to generate from.
    expect($entry['where'] ?? [])->not->toHaveKey('slug');
});

test('reports intercept routes against the page they intercept', function () {
    writeRouteFile($this->sourceDir, 'app/feed/page.tsx');
    writeRouteFile($this->sourceDir, 'app/photo/[id]/page.tsx');
    writeRouteFile($this->sourceDir, 'app/@modal/(.)photo/[id]/page.tsx');

    // Declared the way the build reports it: the interceptor is not a route of
    // its own, and names the url it stands in for.
    $dir = base_path('bootstrap/rsc/vite');

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($dir.'/routes.json', json_encode([
        'version' => 1,
        'routes' => [[
            'component' => 'app/photo/[id]/page',
            'segments' => [['type' => 'static', 'value' => 'photo'], ['type' => 'param', 'value' => 'id']],
            'layouts' => [], 'loadings' => [], 'slots' => [], 'sections' => [],
        ]],
        'intercepts' => [[
            'component' => 'app/@modal/(.)photo/[id]/page',
            'slot' => 'modal',
            'segments' => [['type' => 'static', 'value' => 'photo'], ['type' => 'param', 'value' => 'id']],
            'marker' => '(.)',
        ]],
    ], JSON_THROW_ON_ERROR));

    $entry = collect(manifest())->firstWhere('urlPattern', '/photo/{id}');

    expect($entry['intercepts'][0]['slot'])->toBe('modal')
        ->and($entry['intercepts'][0]['component'])->toBe('app/@modal/(.)photo/[id]/page');
});
