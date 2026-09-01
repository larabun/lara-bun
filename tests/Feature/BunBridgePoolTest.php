<?php

/**
 * Low-level regression tests for the connection-pool hardening:
 *  - readFrame/readExactly reassemble frames without over-reading (audit #6)
 *  - socketHasPendingData detects EOF/desync so stale pooled sockets are
 *    discarded at checkout instead of surfacing as 500s (audit #5)
 *
 * These drive the real (unmocked) BunBridge against in-process socket pairs.
 */

use LaravelRsc\BunBridge;

function bridgeFrame(string $json): string
{
    return pack('N', strlen($json)).$json;
}

function invokeBridge(BunBridge $bridge, string $method, array $args): mixed
{
    $ref = new ReflectionMethod($bridge, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($bridge, $args);
}

function setBridgeProperty(BunBridge $bridge, string $property, mixed $value): void
{
    $ref = new ReflectionProperty($bridge, $property);
    $ref->setAccessible(true);
    $ref->setValue($bridge, $value);
}

/** @return array{0: Socket, 1: Socket} */
function socketPair(): array
{
    $pair = [];
    socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);

    return $pair;
}

test('readFrame reads a single frame back into an array', function () {
    $bridge = new BunBridge;
    [$reader, $writer] = socketPair();

    socket_write($writer, bridgeFrame('{"type":"pong"}'));

    expect(invokeBridge($bridge, 'readFrame', [$reader]))->toBe(['type' => 'pong']);
});

test('readFrame does not over-read across frame boundaries', function () {
    $bridge = new BunBridge;
    [$reader, $writer] = socketPair();

    // Two frames written back-to-back into the same buffer.
    socket_write($writer, bridgeFrame('{"type":"pong"}').bridgeFrame('{"n":1}'));

    expect(invokeBridge($bridge, 'readFrame', [$reader]))->toBe(['type' => 'pong']);
    // The second read must recover the second frame intact — proving the first
    // read consumed exactly its own bytes and left the rest on the wire.
    expect(invokeBridge($bridge, 'readFrame', [$reader]))->toBe(['n' => 1]);
});

test('socketHasPendingData is false for an idle socket and true after the peer closes', function () {
    $bridge = new BunBridge;
    [$a, $b] = socketPair();

    expect(invokeBridge($bridge, 'socketHasPendingData', [$a]))->toBeFalse();

    socket_close($b); // peer closes → EOF becomes readable

    expect(invokeBridge($bridge, 'socketHasPendingData', [$a]))->toBeTrue();
});

test('checkout discards a dead pooled socket and returns a healthy one', function () {
    $bridge = new BunBridge;

    [$aliveA, $aliveB] = socketPair(); // keep $aliveB open so $aliveA stays healthy
    [$deadA, $deadB] = socketPair();
    socket_close($deadB); // $deadA is now at EOF

    // array_pop takes the last element first, so the dead socket is popped
    // (and discarded) before the healthy one.
    setBridgeProperty($bridge, 'pool', [0 => [$aliveA, $deadA]]);

    $result = invokeBridge($bridge, 'checkout', [0]);

    expect($result)->toBe($aliveA);

    // Keep a reference so $aliveB isn't GC'd before the assertion.
    expect($aliveB)->toBeInstanceOf(Socket::class);
});
