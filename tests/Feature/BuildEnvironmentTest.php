<?php

/**
 * The environment the Vite build runs under.
 *
 * The plugin defaults to no backend's conventions, so everything Laravel-shaped
 * has to be passed in — and passed by *both* the build command and the dev
 * watcher. Written out separately, the watcher silently lost the import alias
 * and the route.php marker, so `rsc:dev` produced a different build from
 * `rsc:build` and only the second one was right.
 */

use Illuminate\Support\Facades\Config;
use LaravelRsc\Support\BuildEnvironment;
use LaravelRsc\Support\EnginePath;

test('passes every convention the plugin will not assume', function () {
    $env = BuildEnvironment::forVite();

    expect($env)->toHaveKeys([
        'RSC_PROJECT_ROOT',
        'RSC_OUT_DIR',
        'RSC_ASSETS_DIR',
        'RSC_ASSETS_URL',
        'RSC_HOST_GLOBAL',
        'RSC_PACKAGE_ALIAS',
        'RSC_ROUTE_CONFIG_FILE',
        'RSC_ROUTE_CONFIG_PATTERN',
        'RSC_HOST_ACTIONS',
    ]);

    // Where the app tree lives is declared in the vite config, not here: the
    // build is the only thing that reads it.
    expect($env)->not->toHaveKey('RSC_SOURCE_DIR');
});

test('hands over the actions it discovered, which the build cannot find itself', function () {
    // Reflection over the app's own classes — inherited methods included — is
    // why discovery stays on this side. Rendering the stubs does not need any
    // of that, and does need to know where the app's source lives.
    expect(json_decode(BuildEnvironment::forVite()['RSC_HOST_ACTIONS'], true))->toBeArray();
});

test('the route marker is the file Laravel actually writes', function () {
    $env = BuildEnvironment::forVite();

    expect($env['RSC_ROUTE_CONFIG_FILE'])->toBe('route.php')
        // The pattern has to match a props() closure, which is what makes a
        // route dynamic and therefore un-prerenderable.
        ->and(preg_match('/'.$env['RSC_ROUTE_CONFIG_PATTERN'].'/', 'return route()->props(fn () => []);'))->toBe(1);
});

test('follows configuration where there is a choice to make', function () {
    Config::set('rsc.host_global', 'callHost');

    expect(BuildEnvironment::forVite()['RSC_HOST_GLOBAL'])->toBe('callHost');
});

test('and states the package name rather than taking it as a setting', function () {
    // The alias exists so a vendored copy can be imported by the name it
    // publishes under. That name is not the application's to choose, and a
    // setting that disagreed with it would simply fail to resolve.
    expect(BuildEnvironment::forVite()['RSC_PACKAGE_ALIAS'])->toBe(EnginePath::PACKAGE);
});

test('extra values win, so a caller can ask for a development build', function () {
    expect(BuildEnvironment::forVite(['RSC_DEV' => '1'])['RSC_DEV'])->toBe('1');
});

test('the dev watcher and the build command agree on everything else', function () {
    // The drift that made rsc:dev build differently lived in exactly this gap.
    $build = BuildEnvironment::forVite();
    $watch = BuildEnvironment::forVite(['RSC_DEV' => '1', 'RSC_WATCH' => '1']);

    foreach (array_keys($build) as $key) {
        if (str_starts_with($key, 'RSC_')) {
            expect($watch[$key])->toBe($build[$key]);
        }
    }
});
