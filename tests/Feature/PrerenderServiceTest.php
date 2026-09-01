<?php

// Covers static generation (SSG) route discovery and URL expansion. These are
// the inputs rsc:build iterates to decide what gets prerendered.

use Illuminate\Support\Facades\Route;
use LaraBun\Rsc\PageDefinition;
use LaraBun\Rsc\PageRouteRegistrar;
use LaraBun\Rsc\PrerenderService;

beforeEach(function () {
    $this->prerender = new PrerenderService;
    $this->registrar = new PageRouteRegistrar(app('router'));
    $this->configDir = sys_get_temp_dir().'/rsc-prerender-'.uniqid();
    mkdir($this->configDir, 0755, true);
});

afterEach(function () {
    foreach (glob($this->configDir.'/*') as $file) {
        unlink($file);
    }

    rmdir($this->configDir);
});

/** Write a route.php returning a PageRoute with the given staticPaths. */
function staticPathsConfig(string $dir, string $name, string $paths): string
{
    $path = $dir.'/'.$name.'.php';
    file_put_contents($path, "<?php\n\nreturn LaraBun\\Rsc\\PageRoute::make()->staticPaths({$paths});\n");

    return $path;
}

/** Find a registered RSC route by its URI. */
function rscRoute(string $uri): Illuminate\Routing\Route
{
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === $uri && isset($r->defaults['_rsc_component']));

    expect($route)->not->toBeNull("no RSC route registered for [{$uri}]");

    return $route;
}

test('discovers only RSC routes', function () {
    Route::get('/plain', fn () => 'not rsc');

    $this->registrar->register([
        new PageDefinition(componentName: 'app/page', urlPattern: '/'),
        new PageDefinition(componentName: 'app/docs/page', urlPattern: 'docs'),
    ]);

    $uris = $this->prerender->discoverRscRoutes()->map(fn ($r) => $r->uri())->all();

    expect($uris)->toContain('docs')
        ->and($uris)->not->toContain('plain');
});

test('ignores non-GET rsc routes', function () {
    $this->registrar->register([
        new PageDefinition(componentName: 'app/page', urlPattern: '/'),
    ]);

    $discovered = $this->prerender->discoverRscRoutes();

    expect($discovered)->each->toBeInstanceOf(Illuminate\Routing\Route::class);
    $discovered->each(fn ($r) => expect($r->methods())->toContain('GET'));
});

test('resolves a single url for a route without parameters', function () {
    $this->registrar->register([
        new PageDefinition(componentName: 'app/docs/page', urlPattern: 'docs'),
    ]);

    expect($this->prerender->resolveUrls(rscRoute('docs')))->toBe(['/docs']);
});

test('resolves the root route to a slash', function () {
    $this->registrar->register([
        new PageDefinition(componentName: 'app/page', urlPattern: '/'),
    ]);

    expect($this->prerender->resolveUrls(rscRoute('/')))->toBe(['/']);
});

test('returns no urls for a parameterized route without staticPaths', function () {
    $this->registrar->register([
        new PageDefinition(componentName: 'app/docs/[slug]/page', urlPattern: 'docs/{slug}', isDynamic: true),
    ]);

    // Nothing to prerender — these fall through to the dynamic render path.
    expect($this->prerender->resolveUrls(rscRoute('docs/{slug}')))->toBe([]);
});

test('expands staticPaths given as a flat list of values', function () {
    $this->registrar->register([
        new PageDefinition(
            componentName: 'app/docs/[slug]/page',
            urlPattern: 'docs/{slug}',
            isDynamic: true,
            routeConfigPath: staticPathsConfig($this->configDir, 'docs', "['installation', 'configuration']"),
        ),
    ]);

    expect($this->prerender->resolveUrls(rscRoute('docs/{slug}')))
        ->toBe(['/docs/installation', '/docs/configuration']);
});

test('expands staticPaths given as multi-parameter combinations', function () {
    $this->registrar->register([
        new PageDefinition(
            componentName: 'app/posts/[year]/[slug]/page',
            urlPattern: 'posts/{year}/{slug}',
            isDynamic: true,
            routeConfigPath: staticPathsConfig(
                $this->configDir,
                'posts',
                "[['year' => '2026', 'slug' => 'hello'], ['year' => '2025', 'slug' => 'world']]",
            ),
        ),
    ]);

    expect($this->prerender->resolveUrls(rscRoute('posts/{year}/{slug}')))
        ->toBe(['/posts/2026/hello', '/posts/2025/world']);
});
