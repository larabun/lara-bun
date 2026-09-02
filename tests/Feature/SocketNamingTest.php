<?php

/**
 * The socket path scheme is a contract between two processes: `rsc:serve`
 * creates the files, and the web process connects to them. They derive the path
 * independently, so anything that can differ between a CLI and an FPM process
 * must not influence it.
 *
 * The worker count is exactly such a value — it comes from CPU and memory
 * detection — so naming must not depend on it. When it did, `rsc:serve` created
 * indexed sockets while PHP-FPM looked for the un-indexed base path, and every
 * request failed with a socket-not-found naming a worker that was running.
 */

use Illuminate\Support\Facades\Config;
use LaravelRsc\RuntimeBridge;

function socketPathsFor(int $workers): array
{
    Config::set('rsc.workers', $workers);
    Config::set('rsc.transport', 'unix');
    Config::set('rsc.socket_path', '/tmp/example.sock');

    $bridge = new RuntimeBridge;
    $property = new ReflectionProperty($bridge, 'socketPaths');
    $property->setAccessible(true);

    return $property->getValue($bridge);
}

test('a single worker still gets an indexed socket path', function () {
    expect(socketPathsFor(1))->toBe(['/tmp/example-0.sock']);
});

test('worker zero has the same path whatever the worker count', function () {
    // The whole bug: one process counting 1 and another counting 4 must still
    // agree on where worker zero listens.
    $single = socketPathsFor(1)[0];
    $many = socketPathsFor(4)[0];

    expect($single)->toBe($many);
});

test('each worker gets its own indexed path', function () {
    expect(socketPathsFor(3))->toBe([
        '/tmp/example-0.sock',
        '/tmp/example-1.sock',
        '/tmp/example-2.sock',
    ]);
});

test('callback sockets sit beside their worker socket', function () {
    Config::set('rsc.workers', 2);
    Config::set('rsc.transport', 'unix');
    Config::set('rsc.socket_path', '/tmp/example.sock');

    $bridge = new RuntimeBridge;
    $property = new ReflectionProperty($bridge, 'cbSocketPaths');
    $property->setAccessible(true);

    expect($property->getValue($bridge))->toBe([
        '/tmp/example-0.sock.cb',
        '/tmp/example-1.sock.cb',
    ]);
});

test('the helper strips a .sock suffix rather than doubling it', function () {
    expect(RuntimeBridge::unixSocketPath('/tmp/example.sock', 0))->toBe('/tmp/example-0.sock')
        ->and(RuntimeBridge::unixSocketPath('/tmp/example', 2))->toBe('/tmp/example-2.sock');
});

test('falls back to a listening worker when this side counted too high', function () {
    // PHP-FPM may resolve more workers than are actually running. Connecting to
    // the missing index would fail the request; using one that exists only
    // costs spread across the pool.
    $dir = sys_get_temp_dir().'/rsc-naming-'.uniqid();
    mkdir($dir, 0755, true);

    Config::set('rsc.workers', 4);
    Config::set('rsc.transport', 'unix');
    Config::set('rsc.socket_path', $dir.'/w.sock');

    // Only worker 1 is listening.
    touch($dir.'/w-1.sock');

    $bridge = new RuntimeBridge;
    $resolve = new ReflectionMethod($bridge, 'availableWorker');
    $resolve->setAccessible(true);

    expect($resolve->invoke($bridge, 3))->toBe(1)
        ->and($resolve->invoke($bridge, 1))->toBe(1);

    unlink($dir.'/w-1.sock');
    rmdir($dir);
});

test('socketFiles lists the indexed worker and callback sockets', function () {
    // Callers clear stale sockets through this rather than rebuilding the path
    // scheme themselves — the duplicate scheme in PrerenderService is what left
    // `rsc:build` waiting on a file the worker no longer creates.
    Config::set('rsc.workers', 2);
    Config::set('rsc.transport', 'unix');
    Config::set('rsc.socket_path', '/tmp/example.sock');

    expect((new RuntimeBridge)->socketFiles())->toBe([
        '/tmp/example-0.sock',
        '/tmp/example-1.sock',
        '/tmp/example-0.sock.cb',
        '/tmp/example-1.sock.cb',
    ]);
});

test('socketFiles is empty under the tcp transport, which has no files', function () {
    Config::set('rsc.workers', 2);
    Config::set('rsc.transport', 'tcp');

    expect((new RuntimeBridge)->socketFiles())->toBe([]);
});

test('the single-worker path binds where php connects', function () {
    // rsc:serve has two paths: several workers, which indexes correctly, and
    // one worker, which passed the raw path to the worker while logging and
    // connecting to the indexed one. Nothing hit it because the default worker
    // count is above one — but dev mode forces exactly one worker, so it hit
    // every request, and the error named a socket the running worker had not
    // created.
    $source = file_get_contents(__DIR__.'/../../src/Console/ServeCommand.php');
    $start = strpos($source, 'private function serveSingle(');
    $end = strpos($source, 'private function serveMultiple(');
    $body = substr($source, $start, $end - $start);

    expect($body)->toContain('$this->spawnWorker($runtimePath, $workerPath, 0, $this->socketPaths[0],')
        ->and($body)->not->toContain('$this->spawnWorker($runtimePath, $workerPath, 0, $socketPath,');
});
