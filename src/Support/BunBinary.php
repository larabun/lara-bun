<?php

namespace LaravelRsc\Support;

use RuntimeException;

class BunBinary
{
    /**
     * Resolve the Bun executable path.
     *
     * Honors the configured `bun.binary` (BUN_BINARY) first — absolute, or
     * relative to the app base path — then common install locations and PATH.
     */
    public static function resolve(): ?string
    {
        $configured = config('bun.binary');

        if (is_string($configured) && $configured !== '') {
            $path = self::absolutePath($configured);

            if (is_executable($path)) {
                return $path;
            }
        }

        foreach (self::candidates() as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        $which = trim((string) shell_exec('which bun 2>/dev/null'));

        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        return null;
    }

    /**
     * Common Bun install locations to probe when BUN_BINARY is not set.
     *
     * @return string[]
     */
    public static function candidates(): array
    {
        return [
            '/opt/homebrew/bin/bun',
            '/usr/local/bin/bun',
            ($_SERVER['HOME'] ?? '').'/.bun/bin/bun',
        ];
    }

    /**
     * Turn a possibly-relative path into an absolute one, resolving relative
     * paths from the app base path when the helper is available.
     */
    public static function absolutePath(string $path): string
    {
        $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR)
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);

        if ($isAbsolute) {
            return $path;
        }

        return function_exists('base_path') ? base_path($path) : $path;
    }

    /**
     * Map the current platform to the Bun GitHub release asset and the binary
     * name inside it. Pure so it can be unit-tested across platforms.
     *
     * @param  string  $osFamily  PHP_OS_FAMILY (Darwin | Linux | Windows | ...)
     * @param  string  $machine  php_uname('m') (arm64 | aarch64 | x86_64 | amd64 | ...)
     * @return array{asset: string, binary: string}
     */
    public static function releaseAsset(string $osFamily, string $machine, bool $musl = false): array
    {
        $os = match ($osFamily) {
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            'Windows' => 'windows',
            default => throw new RuntimeException("Unsupported OS for Bun: {$osFamily}"),
        };

        $arch = match (strtolower($machine)) {
            'arm64', 'aarch64' => 'aarch64',
            'x86_64', 'amd64', 'x64' => 'x64',
            default => throw new RuntimeException("Unsupported CPU architecture for Bun: {$machine}"),
        };

        $asset = "bun-{$os}-{$arch}";

        // musl (Alpine) builds exist for Linux only.
        if ($musl && $os === 'linux') {
            $asset .= '-musl';
        }

        $binary = $os === 'windows' ? 'bun.exe' : 'bun';

        return ['asset' => $asset, 'binary' => $binary];
    }
}
