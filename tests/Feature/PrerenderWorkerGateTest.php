<?php

/**
 * `rsc:build` starts a worker and waits for it. That wait used to be a
 * file_exists() check on a path PrerenderService derived itself, which broke
 * the moment socket naming changed: the build sat out its 15s timeout and
 * reported "Bun worker failed to start" while four workers were listening.
 *
 * Readiness is a ping now. Nothing outside RuntimeBridge may reconstruct the
 * socket path scheme, which is the invariant these tests hold.
 */

use LaravelRsc\PrerenderService;
use LaravelRsc\RuntimeBridge;

test('a responding worker is reused rather than restarted', function () {
    $bridge = Mockery::mock(RuntimeBridge::class);
    $bridge->shouldReceive('ping')->andReturn(true);

    // Bound rather than instance()d: workerResponds() forgets the resolved
    // instance so a restarted worker is never answered by a stale socket.
    app()->bind(RuntimeBridge::class, fn () => $bridge);

    expect(app(PrerenderService::class)->ensureBunWorker())->toBeNull();
});

test('readiness never depends on a socket file existing', function () {
    // The whole failure: a path that no process creates any more still had to
    // appear on disk before the build would proceed.
    $source = file_get_contents(__DIR__.'/../../src/PrerenderService.php');

    expect($source)->not->toContain("config('rsc.socket_path'")
        ->and($source)->not->toContain('rsc.socket_path');
});

test('stale sockets are cleared through the bridge, not a rebuilt path', function () {
    $cleared = [];
    $dir = sys_get_temp_dir().'/rsc-gate-'.uniqid();
    mkdir($dir, 0755, true);
    touch($dir.'/w-0.sock');

    $bridge = Mockery::mock(RuntimeBridge::class);
    $bridge->shouldReceive('ping')->andReturn(false);
    $bridge->shouldReceive('disconnect')->once();
    $bridge->shouldReceive('socketFiles')->andReturnUsing(function () use ($dir, &$cleared) {
        $cleared[] = 'asked';

        return [$dir.'/w-0.sock'];
    });

    app()->bind(RuntimeBridge::class, fn () => $bridge);

    $service = app(PrerenderService::class);
    $clear = new ReflectionMethod($service, 'clearWorkerSockets');
    $clear->setAccessible(true);
    $clear->invoke($service);

    expect($cleared)->toBe(['asked'])
        ->and(file_exists($dir.'/w-0.sock'))->toBeFalse();

    rmdir($dir);
});
