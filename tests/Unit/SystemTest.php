<?php

use RscKit\Support\System;

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

test('memoryLimitMb is either unknown or a positive size', function () {
    $limit = System::memoryLimitMb();

    expect($limit === null || $limit > 0)->toBeTrue();
});

// ─── cgroup v2 cpu.max ───────────────────────────────────────────────────────

test('parses a cgroup v2 cpu quota into whole cores', function () {
    // 200000/100000 = 2 cores.
    expect(System::parseCpuMax('200000 100000'))->toBe(2);
});

test('rounds a fractional cpu quota up to one usable core', function () {
    // Half a core still lets one worker run.
    expect(System::parseCpuMax('50000 100000'))->toBe(1);
});

test('treats an unlimited cpu quota as no constraint', function () {
    expect(System::parseCpuMax('max 100000'))->toBeNull();
});

test('ignores malformed cpu.max values', function () {
    expect(System::parseCpuMax(''))->toBeNull()
        ->and(System::parseCpuMax('garbage'))->toBeNull()
        ->and(System::parseCpuMax('200000 0'))->toBeNull();
});

// ─── cgroup memory limits ────────────────────────────────────────────────────

test('parses a memory limit into megabytes', function () {
    expect(System::parseMemoryLimit((string) (512 * 1024 * 1024)))->toBe(512);
});

test('treats an unlimited memory limit as unknown', function () {
    expect(System::parseMemoryLimit('max'))->toBeNull();
});

test('ignores the cgroup v1 unlimited sentinel', function () {
    // v1 reports a huge number rather than "max" when there is no limit.
    expect(System::parseMemoryLimit('9223372036854771712'))->toBeNull();
});

test('ignores malformed memory limit values', function () {
    expect(System::parseMemoryLimit(''))->toBeNull()
        ->and(System::parseMemoryLimit('garbage'))->toBeNull()
        ->and(System::parseMemoryLimit('0'))->toBeNull();
});
