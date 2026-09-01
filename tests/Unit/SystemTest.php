<?php

use LaraBun\Support\System;

test('cpuCount returns at least one core', function () {
    expect(System::cpuCount())->toBeInt()->toBeGreaterThanOrEqual(1);
});

test('defaultWorkerCount is at least one and respects the cap', function () {
    expect(System::defaultWorkerCount(4))
        ->toBeGreaterThanOrEqual(1)
        ->toBeLessThanOrEqual(4);

    // A cap of 1 always yields a single worker regardless of core count.
    expect(System::defaultWorkerCount(1))->toBe(1);
});

test('defaultWorkerCount never exceeds the core count', function () {
    expect(System::defaultWorkerCount(1024))->toBeLessThanOrEqual(System::cpuCount());
});
