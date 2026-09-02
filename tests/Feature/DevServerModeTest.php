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
    $dir = EnginePath::directory();

    expect($dir)->not->toBeNull()
        ->and(is_file($dir.'/vite.ts'))->toBeTrue()
        ->and(is_file($dir.'/worker.ts'))->toBeTrue()
        ->and(is_dir($dir.'/js'))->toBeTrue();
});
