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
use LaravelRsc\Console\RscExportCommand;
use LaravelRsc\Support\BuildEnvironment;
use LaravelRsc\Support\EnginePath;

test('passes every convention the plugin will not assume', function () {
    $env = BuildEnvironment::forVite();

    expect($env)->toHaveKeys([
        'RSC_PROJECT_ROOT',
        'RSC_SOURCE_DIR',
        'RSC_OUT_DIR',
        'RSC_ASSETS_DIR',
        'RSC_ASSETS_URL',
        'RSC_HOST_GLOBAL',
        'RSC_PACKAGE_ALIAS',
        'RSC_ROUTE_CONFIG_FILE',
        'RSC_ROUTE_CONFIG_PATTERN',
    ]);
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

test('an export build asks for payloads by the name the export writes', function () {
    // The client is built to request this and the export writes it; a setting
    // that changed one without the other would 404 every navigation.
    Config::set('rsc.output', 'export');

    expect(BuildEnvironment::forVite()['RSC_STATIC_PAYLOADS'])
        ->toBe(RscExportCommand::PAYLOAD_NAME);
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

test('an export build tells the client to ask for payloads by url', function () {
    // There is no server to read the X-RSC header on a static host, so the
    // client has to know before it is built that payloads live somewhere else.
    Config::set('rsc.output', 'export');
    Config::set('rsc.export_payload_name', 'index.rsc');

    expect(BuildEnvironment::forVite()['RSC_STATIC_PAYLOADS'])->toBe('index.rsc');
});

test('an ordinary build leaves the header doing the work', function () {
    Config::set('rsc.output', 'server');

    expect(BuildEnvironment::forVite()['RSC_STATIC_PAYLOADS'])->toBe('');
});
