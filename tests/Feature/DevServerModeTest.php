<?php

/**
 * Dev mode renders from source through a Vite dev server instead of a bundle.
 *
 * The failures it guards against are all silent: the app still answers 200 and
 * looks right, while nothing you edit has any effect.
 */

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use LaravelRsc\Http\Middleware\ServeStaticRsc;
use LaravelRsc\Support\DevServer;
use LaravelRsc\Support\EnginePath;

afterEach(function () {
    DevServer::stop();
});

test('the hot file tells the web process the dev server is running', function () {
    // rsc:dev and PHP-FPM are different processes with different environments,
    // so the web side cannot be told — it has to read this, the same way
    // Laravel's own Vite integration does.
    expect(DevServer::isActive())->toBeFalse()
        ->and(DevServer::origin())->toBeNull();

    DevServer::start('http://localhost:5173');

    expect(DevServer::isActive())->toBeTrue()
        ->and(DevServer::origin())->toBe('http://localhost:5173');

    DevServer::stop();

    expect(DevServer::isActive())->toBeFalse();
});

test('static prerendered output is bypassed while the dev server runs', function () {
    // Most routes in a real app are prerendered, so without this the first
    // request is answered from the last production build and the worker is
    // never reached: the app runs, and no edit ever appears.
    $basePath = sys_get_temp_dir().'/rsc-dev-static-'.uniqid();
    mkdir($basePath.'/docs', 0755, true);
    file_put_contents($basePath.'/docs/rsc.html', '<html>prerendered</html>');

    Config::set('rsc.static_path', $basePath);

    $middleware = new ServeStaticRsc;
    $request = Request::create('/docs/rsc');
    $next = fn () => new Response('live', 200);

    expect($middleware->handle($request, $next)->getContent())->toBe('<html>prerendered</html>');

    DevServer::start('http://localhost:5173');

    expect($middleware->handle($request, $next)->getContent())->toBe('live');

    unlink($basePath.'/docs/rsc.html');
    rmdir($basePath.'/docs');
    rmdir($basePath);
});

test('the engine directory is the one holding the plugin, not the package root', function () {
    // In dev the worker runs the Vite plugin itself, which resolves js/ against
    // this. rsc:serve used to build the path by hand and passed the Composer
    // package root, one level above — so the client runtime could not be found.
    //
    // The npm package publishes its source under src/, so this is a level
    // below the package root as well: pointing at the root finds nothing, and
    // finding nothing is indistinguishable from not being installed.
    $engine = sys_get_temp_dir().'/rsc-engine-'.uniqid();

    mkdir($engine.'/js', 0755, true);
    touch($engine.'/vite.ts');
    touch($engine.'/worker.ts');

    putenv("RSC_ENGINE_DIR={$engine}");

    $dir = EnginePath::directory();

    putenv('RSC_ENGINE_DIR');

    expect($dir)->toBe($engine)
        ->and(is_file($dir.'/vite.ts'))->toBeTrue()
        ->and(is_dir($dir.'/js'))->toBeTrue();

    unlink($engine.'/vite.ts');
    unlink($engine.'/worker.ts');
    rmdir($engine.'/js');
    rmdir($engine);
});

test('and is null when the engine is not installed', function () {
    // This package no longer carries a copy of the engine, so "not found" is a
    // real state rather than an impossible one. The commands that need it say
    // which package to install rather than failing on a path.
    expect(EnginePath::directory())->toBeNull();
});
