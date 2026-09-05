<?php

namespace RscKit\Console;

use Illuminate\Console\Command;
use RscKit\Support\RuntimeBinary;
use RuntimeException;
use ZipArchive;

class InstallRuntimeCommand extends Command
{
    protected $signature = 'rsc:install
        {--release=latest : Bun version to install (e.g. 1.1.30) or "latest"}
        {--path= : Destination path for the binary (default: base_path("bin/bun"))}
        {--musl : Download the musl (Alpine) Linux build}
        {--force : Re-download even if a Bun binary already exists at the path}';

    protected $description = 'Download a self-contained Bun binary into the app (for hosts without Bun, e.g. Laravel Cloud)';

    public function handle(): int
    {
        try {
            ['asset' => $asset, 'binary' => $binaryName] = RuntimeBinary::releaseAsset(
                PHP_OS_FAMILY,
                php_uname('m'),
                (bool) $this->option('musl'),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $target = $this->targetPath($binaryName);

        if (! $this->option('force') && is_executable($target)) {
            $this->info("Bun already installed at {$target} ({$this->binaryVersion($target)}).");
            $this->line('Use --force to re-download.');

            return self::SUCCESS;
        }

        $url = $this->downloadUrl($asset, (string) $this->option('release'));
        $this->info("Downloading {$asset} from {$url}");

        $workDir = $this->makeTempDir();
        $zipPath = $workDir.'/bun.zip';

        try {
            if (! $this->download($url, $zipPath)) {
                $this->error('Download failed. Check the version/platform or your network.');

                return self::FAILURE;
            }

            $extracted = $this->extract($zipPath, $workDir, $binaryName);

            if ($extracted === null) {
                $this->error("Could not find {$binaryName} inside the downloaded archive.");

                return self::FAILURE;
            }

            $this->ensureDirectory(dirname($target));

            if (! @rename($extracted, $target)) {
                // rename() fails across filesystems; fall back to copy.
                if (! @copy($extracted, $target)) {
                    $this->error("Failed to write binary to {$target}.");

                    return self::FAILURE;
                }
            }

            @chmod($target, 0755);
        } finally {
            $this->removeDirectory($workDir);
        }

        if (! is_executable($target)) {
            $this->error("Installed binary at {$target} is not executable.");

            return self::FAILURE;
        }

        $version = $this->binaryVersion($target);
        $this->info("Bun {$version} installed at {$target}");
        $this->newLine();
        $this->line('Point Laravel RSC at it by setting in your .env:');
        $this->line('  RSC_RUNTIME_BINARY='.$this->envValue($target));

        return self::SUCCESS;
    }

    private function targetPath(string $binaryName): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return RuntimeBinary::absolutePath($path);
        }

        return base_path('bin/'.$binaryName);
    }

    private function downloadUrl(string $asset, string $version): string
    {
        if ($version === '' || strtolower($version) === 'latest') {
            return "https://github.com/oven-sh/bun/releases/latest/download/{$asset}.zip";
        }

        // Accept "1.1.30", "v1.1.30", or "bun-v1.1.30".
        $tag = preg_replace('/^(bun-)?v?/', '', $version);

        return "https://github.com/oven-sh/bun/releases/download/bun-v{$tag}/{$asset}.zip";
    }

    private function download(string $url, string $dest): bool
    {
        // Prefer curl — streams to disk and follows GitHub's cross-host
        // redirects reliably. Fall back to PHP streams when curl is absent.
        if ($this->hasCommand('curl')) {
            $process = proc_open(
                ['curl', '-fSL', '--retry', '3', '-o', $dest, $url],
                [1 => ['pipe', 'w'], 2 => STDERR],
                $pipes,
            );

            if (is_resource($process)) {
                fclose($pipes[1]);

                return proc_close($process) === 0 && is_file($dest) && filesize($dest) > 0;
            }
        }

        $context = stream_context_create([
            'http' => ['header' => "User-Agent: RscKit\r\n", 'follow_location' => 1, 'timeout' => 300],
            'https' => ['header' => "User-Agent: RscKit\r\n", 'follow_location' => 1, 'timeout' => 300],
        ]);

        return @copy($url, $dest, $context) && is_file($dest) && filesize($dest) > 0;
    }

    private function extract(string $zipPath, string $workDir, string $binaryName): ?string
    {
        $extractDir = $workDir.'/extracted';
        $this->ensureDirectory($extractDir);

        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive;

            if ($zip->open($zipPath) === true) {
                $zip->extractTo($extractDir);
                $zip->close();
            } else {
                return null;
            }
        } elseif ($this->hasCommand('unzip')) {
            $process = proc_open(
                ['unzip', '-o', '-q', $zipPath, '-d', $extractDir],
                [1 => STDERR, 2 => STDERR],
                $pipes,
            );

            if (! is_resource($process) || proc_close($process) !== 0) {
                return null;
            }
        } else {
            $this->error('Neither the zip extension nor the unzip command is available.');

            return null;
        }

        // The archive contains a single "bun-<os>-<arch>/<binary>" entry.
        $matches = glob($extractDir.'/*/'.$binaryName) ?: [];

        return $matches[0] ?? null;
    }

    private function binaryVersion(string $path): string
    {
        $process = proc_open(
            [$path, '--version'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            return 'unknown';
        }

        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $out === '' ? 'unknown' : 'v'.$out;
    }

    private function envValue(string $target): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($target, $base)
            ? substr($target, strlen($base))
            : $target;
    }

    private function hasCommand(string $command): bool
    {
        return trim((string) shell_exec('command -v '.escapeshellarg($command).' 2>/dev/null')) !== '';
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/larabun-bun-'.bin2hex(random_bytes(6));
        $this->ensureDirectory($dir);

        return $dir;
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
