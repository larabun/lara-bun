<?php

use LaravelRsc\BunBridge;

test('tcpPorts assigns non-overlapping main and callback port ranges', function () {
    // 3 workers, base 7940: mains 7940-7942, callbacks 7943-7945.
    expect(BunBridge::tcpPorts(7940, 3, 0))->toBe(['main' => 7940, 'cb' => 7943]);
    expect(BunBridge::tcpPorts(7940, 3, 1))->toBe(['main' => 7941, 'cb' => 7944]);
    expect(BunBridge::tcpPorts(7940, 3, 2))->toBe(['main' => 7942, 'cb' => 7945]);
});

test('tcpPorts keeps single-worker callback adjacent to main', function () {
    expect(BunBridge::tcpPorts(7940, 1, 0))->toBe(['main' => 7940, 'cb' => 7941]);
});

test('tcpPorts main and callback ranges never collide', function () {
    $count = 8;
    $ports = [];

    for ($i = 0; $i < $count; $i++) {
        $p = BunBridge::tcpPorts(7940, $count, $i);
        $ports[] = $p['main'];
        $ports[] = $p['cb'];
    }

    // Every assigned port is unique across all mains and callbacks.
    expect(count(array_unique($ports)))->toBe(count($ports));
});
