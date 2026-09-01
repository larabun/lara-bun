<?php

use LaraBun\Support\BunBinary;

test('releaseAsset maps macOS arm64 to the darwin aarch64 build', function () {
    expect(BunBinary::releaseAsset('Darwin', 'arm64'))
        ->toBe(['asset' => 'bun-darwin-aarch64', 'binary' => 'bun']);
});

test('releaseAsset maps linux x86_64 to the linux x64 build', function () {
    expect(BunBinary::releaseAsset('Linux', 'x86_64'))
        ->toBe(['asset' => 'bun-linux-x64', 'binary' => 'bun']);
});

test('releaseAsset appends -musl only for linux', function () {
    expect(BunBinary::releaseAsset('Linux', 'x86_64', musl: true)['asset'])
        ->toBe('bun-linux-x64-musl');

    // musl flag is ignored on non-linux platforms
    expect(BunBinary::releaseAsset('Darwin', 'arm64', musl: true)['asset'])
        ->toBe('bun-darwin-aarch64');
});

test('releaseAsset uses bun.exe on Windows', function () {
    expect(BunBinary::releaseAsset('Windows', 'x64'))
        ->toBe(['asset' => 'bun-windows-x64', 'binary' => 'bun.exe']);
});

test('releaseAsset rejects unsupported OS and architecture', function () {
    expect(fn () => BunBinary::releaseAsset('FreeBSD', 'x86_64'))
        ->toThrow(RuntimeException::class);

    expect(fn () => BunBinary::releaseAsset('Linux', 'riscv64'))
        ->toThrow(RuntimeException::class);
});

test('resolve honors an executable configured binary path first', function () {
    // /bin/sh is guaranteed executable on the CI/dev platforms we target.
    config()->set('bun.binary', '/bin/sh');

    expect(BunBinary::resolve())->toBe('/bin/sh');
});

test('resolve falls through when the configured binary is not executable', function () {
    config()->set('bun.binary', '/nonexistent/path/to/bun');

    // Should not return the bogus path; either finds a real bun or null.
    expect(BunBinary::resolve())->not->toBe('/nonexistent/path/to/bun');
});

test('absolutePath resolves relative paths from the base path and leaves absolute paths alone', function () {
    expect(BunBinary::absolutePath('/opt/bun'))->toBe('/opt/bun');
    expect(BunBinary::absolutePath('bin/bun'))->toBe(base_path('bin/bun'));
});
