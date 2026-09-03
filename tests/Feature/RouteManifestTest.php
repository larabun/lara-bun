<?php

/**
 * The route tree, read rather than walked.
 *
 * The plugin walks app/ to generate its entries and writes down what it found;
 * this turns that into the page definitions routing needs. It replaced a
 * scanner that walked the same directories a second time, and was checked
 * against it — every expectation below was first established by comparing the
 * two while both existed.
 */

use Illuminate\Support\Collection;
use LaravelRsc\RouteManifest;

/** The fixture the JS suite builds, which has the awkward cases in it. */
function fixtureApp(): string
{
    return dirname(__DIR__, 2).'/tests/fixtures/rsc-app/app';
}

function fixtureManifest(): string
{
    return dirname(__DIR__, 2).'/bootstrap/rsc/vite-test/routes.json';
}

function fixturePages(): Collection
{
    return collect((new RouteManifest(fixtureManifest(), fixtureApp()))->pages())
        ->keyBy('urlPattern');
}

beforeEach(function () {
    if (! is_file(fixtureManifest())) {
        $this->markTestSkipped('fixture not built; run: bun test tests/js/routeManifest.test.ts');
    }
});

test('a page carries the layouts that wrap it, outermost first', function () {
    $pages = fixturePages();

    expect($pages['nested']->layouts)->toBe(['app/layout', 'app/nested/layout'])
        ->and($pages['feed']->layouts)->toBe(['app/layout']);
});

test('a sibling whose name merely begins the same is not an ancestor', function () {
    // app/slow3 begins with app/slow as text and is not inside it. Compared as
    // strings, /slow3 inherited the loading state of /slow.
    $pages = fixturePages();

    expect($pages['slow3']->loadings)->toBe(['app/loading', 'app/slow3/loading'])
        ->and($pages['slow']->loadings)->toBe(['app/loading', 'app/slow/loading']);
});

test('a route group contributes no url segment', function () {
    $urls = fixturePages()->keys();

    expect($urls)->toContain('promo')
        ->and($urls->filter(fn ($u) => str_contains($u, '(')))->toBeEmpty();
});

test('an interceptor is attached to what it intercepts, not registered as a page', function () {
    // Registering it as its own page would make it navigable, which is the
    // opposite of the point.
    $pages = fixturePages();

    expect($pages->keys()->filter(fn ($u) => str_contains($u, '@')))->toBeEmpty();
    expect($pages['photo/{id}']->interceptRoutes)->toHaveCount(1)
        ->and($pages['photo/{id}']->interceptRoutes[0]['slot'])->toBe('modal');
});

test('a page carries the slots that belong to it', function () {
    expect(fixturePages()['feed']->parallelSlots)->toBe(['modal' => 'app/@modal/default']);
});

test('route.php stays this host’s business, found beside the page', function () {
    // The build has no reason to know about a Laravel convention, so the path
    // is worked out here rather than carried in the manifest.
    $pages = fixturePages();

    foreach ($pages as $page) {
        if ($page->routeConfigPath !== null) {
            expect($page->routeConfigPath)->toEndWith('/route.php')
                ->and(is_file($page->routeConfigPath))->toBeTrue();
        }
    }

    expect(true)->toBeTrue();
});

test('a missing manifest says how to make one', function () {
    // Routing depends on a build artifact now, so the failure has to name the
    // command rather than quietly registering no routes at all.
    $read = new RouteManifest('/nonexistent/routes.json', fixtureApp());

    expect(fn () => $read->pages())->toThrow(RuntimeException::class, 'rsc:build');
});

test('an unsupported interception marker is refused, not guessed', function () {
    // (..) names a url relative to somewhere other than here. Guessing would
    // attach the interceptor to a page it was never meant for.
    $dir = sys_get_temp_dir().'/rsc-manifest-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/routes.json', json_encode([
        'version' => 1,
        'routes' => [],
        'intercepts' => [[
            'component' => 'app/@modal/(..)photo/[id]/page',
            'slot' => 'modal',
            'segments' => [['type' => 'static', 'value' => 'photo']],
            'marker' => '(..)',
        ]],
    ]));

    expect(fn () => (new RouteManifest($dir.'/routes.json', fixtureApp()))->pages())
        ->toThrow(RuntimeException::class, '(..)');

    unlink($dir.'/routes.json');
    rmdir($dir);
});
