<?php

/**
 * A route's classification is not fixed, and the artifacts differ by kind.
 *
 * Making a static page read the request turns it into PPR, which writes
 * `.ppr.html` and never touches the `.html`/`.flight` the static build left.
 * ServeStaticRsc prefers `.flight`, so the page went on answering from a build
 * that predated the change — the same frozen copy for every query, with
 * nothing to say so. `--clean` fixed it, once you knew to suspect it.
 */

use LaravelRsc\PrerenderService;

function artifactDir(): string
{
    $dir = sys_get_temp_dir().'/rsc-stale-'.uniqid();
    mkdir($dir.'/docs', 0755, true);

    return $dir;
}

/** Everything a static prerender of /docs/search would have written. */
function writeStaticArtifacts(string $dir): void
{
    file_put_contents($dir.'/docs/search.html', 'old html');
    file_put_contents($dir.'/docs/search.flight', 'old flight');
    file_put_contents($dir.'/docs/search.meta.json', '{}');
    file_put_contents($dir.'/docs/search.seg1.flight', 'old seg1');
    file_put_contents($dir.'/docs/search.seg2.flight', 'old seg2');
}

test('the artifacts of a previous build are gone before the next one writes', function () {
    $dir = artifactDir();
    writeStaticArtifacts($dir);

    app(PrerenderService::class)->purgeArtifacts($dir, '/docs/search');

    expect(glob($dir.'/docs/search*'))->toBe([]);

    File::deleteDirectory($dir);
});

test('the segment variants go too, however many there were', function () {
    // How many exist is a property of the build being replaced, so they cannot
    // be removed by name — a chain that got shorter would strand the deepest.
    $dir = artifactDir();

    foreach (range(1, 5) as $depth) {
        file_put_contents($dir."/docs/search.seg{$depth}.flight", 'old');
    }

    app(PrerenderService::class)->purgeArtifacts($dir, '/docs/search');

    expect(glob($dir.'/docs/search.seg*.flight'))->toBe([]);

    File::deleteDirectory($dir);
});

test('a route that became PPR stops answering from its static copy', function () {
    // The whole failure: .ppr.html is written, .flight is left behind, and
    // ServeStaticRsc reaches the .flight first.
    $dir = artifactDir();
    writeStaticArtifacts($dir);

    app(PrerenderService::class)->purgeArtifacts($dir, '/docs/search');
    file_put_contents($dir.'/docs/search.ppr.html', 'new shell');
    file_put_contents($dir.'/docs/search.ppr-meta.json', '{}');

    expect(file_exists($dir.'/docs/search.flight'))->toBeFalse()
        ->and(file_exists($dir.'/docs/search.html'))->toBeFalse()
        ->and(file_exists($dir.'/docs/search.ppr.html'))->toBeTrue();

    File::deleteDirectory($dir);
});

test('another route is untouched', function () {
    // Purging is per url. Clearing the whole directory would break a build
    // that only prerenders some routes, which is what --skip-prerender does.
    $dir = artifactDir();
    writeStaticArtifacts($dir);
    file_put_contents($dir.'/docs/other.flight', 'keep me');

    app(PrerenderService::class)->purgeArtifacts($dir, '/docs/search');

    expect(file_exists($dir.'/docs/other.flight'))->toBeTrue();

    File::deleteDirectory($dir);
});

test('the index route resolves to a path rather than an empty name', function () {
    $dir = artifactDir();
    file_put_contents($dir.'/index.flight', 'old');

    app(PrerenderService::class)->purgeArtifacts($dir, '/');

    expect(file_exists($dir.'/index.flight'))->toBeFalse();

    File::deleteDirectory($dir);
});
