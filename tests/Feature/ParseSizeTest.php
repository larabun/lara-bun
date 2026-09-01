<?php

use LaravelRsc\RuntimeBridge;

it('parses megabytes', function () {
    expect(RuntimeBridge::parseSize('25mb'))->toBe(25 * 1024 * 1024);
    expect(RuntimeBridge::parseSize('100mb'))->toBe(100 * 1024 * 1024);
    expect(RuntimeBridge::parseSize('1MB'))->toBe(1024 * 1024);
});

it('parses kilobytes', function () {
    expect(RuntimeBridge::parseSize('512kb'))->toBe(512 * 1024);
    expect(RuntimeBridge::parseSize('1KB'))->toBe(1024);
});

it('parses gigabytes', function () {
    expect(RuntimeBridge::parseSize('1gb'))->toBe(1024 * 1024 * 1024);
});

it('parses plain bytes', function () {
    expect(RuntimeBridge::parseSize('1048576b'))->toBe(1048576);
    expect(RuntimeBridge::parseSize('1024'))->toBe(1024);
});

it('falls back to 1mb for invalid input', function () {
    expect(RuntimeBridge::parseSize('invalid'))->toBe(1024 * 1024);
    expect(RuntimeBridge::parseSize(''))->toBe(1024 * 1024);
});
