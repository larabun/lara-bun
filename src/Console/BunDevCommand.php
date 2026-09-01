<?php

namespace LaravelRsc\Console;

use Illuminate\Console\Command;
use LaravelRsc\Support\BunBinary;
use Symfony\Component\Process\Process;

class BunDevCommand extends Command
{
    protected $signature = 'bun:dev {--socket= : Path to the Unix socket}';

    protected $description = 'Start the build watcher and Bun worker for development';

    /** @var Process|null */
    private $buildProcess = null;

    /** @var Process|null */
    private $serveProcess = null;

    public function handle(): int
    {
        $bunPath = $this->findBun();

        if ($bunPath === null) {
            $this->error('Bun executable not found. Run: php artisan bun:install (or set BUN_BINARY to its path).');

            return self::FAILURE;
        }

        $buildScript = $this->getBuildScript();

        if (! file_exists($buildScript)) {
            $this->error("Build script not found: {$buildScript}");

            return self::FAILURE;
        }

        $this->trapSignals();

        // Step 1: Run initial build
        $this->info('Running initial build...');
        $this->newLine();

        $initialBuild = new Process(
            [$bunPath, $buildScript],
            base_path(),
            $this->viteBuildEnv(),
        );
        $initialBuild->setTimeout(120);
        $initialBuild->run(fn ($type, $buffer) => $this->output->write($buffer));

        $buildSucceeded = $initialBuild->isSuccessful();

        if (! $buildSucceeded) {
            $this->warn('Initial build failed — starting watcher so you can fix errors.');
            $this->newLine();
        }

        // Step 2: Start the Vite build watcher in the background (rebuilds on
        // source change; the worker restarts via bun:serve --watch).
        $this->buildProcess = new Process(
            [$bunPath, $buildScript],
            base_path(),
            $this->viteBuildEnv(['BUN_RSC_WATCH' => '1']),
        );
        $this->buildProcess->setTimeout(null);
        $this->buildProcess->start(fn ($type, $buffer) => $this->output->write($buffer));

        $this->info('Build watcher started.');

        // Step 3: Start bun:serve --watch (skip if no bundle yet — watcher will trigger it)
        $socketOption = $this->option('socket') ? ['--socket='.$this->option('socket')] : [];
        $bundlePath = config('bun.rsc.bundle', base_path('bootstrap/rsc/entry.rsc.js'));
        $canServe = $buildSucceeded && file_exists($bundlePath);

        if ($canServe) {
            $this->serveProcess = new Process(
                ['php', 'artisan', 'bun:serve', '--watch', ...$socketOption],
                base_path(),
            );
            $this->serveProcess->setTimeout(null);
            $this->serveProcess->start(fn ($type, $buffer) => $this->output->write($buffer));
        } else {
            $this->warn('Waiting for a successful build before starting the worker...');
        }

        $this->newLine();
        $this->info('Development server started. Press Ctrl+C to stop.');
        $this->newLine();

        $this->trapSignals();

        // Step 4: Monitor processes — start worker when build succeeds
        while ($this->buildProcess->isRunning() || $this->serveProcess?->isRunning()) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // If worker isn't running yet but the bundle now exists, start it
            if ($this->serveProcess === null && file_exists($bundlePath)) {
                $this->info('Build succeeded — starting worker...');
                $this->serveProcess = new Process(
                    ['php', 'artisan', 'bun:serve', '--watch', ...$socketOption],
                    base_path(),
                );
                $this->serveProcess->setTimeout(null);
                $this->serveProcess->start(fn ($type, $buffer) => $this->output->write($buffer));
            }

            // If build watcher dies, stop everything
            if (! $this->buildProcess->isRunning() && $this->serveProcess?->isRunning()) {
                $this->error('Build watcher stopped unexpectedly.');
                $this->shutdown();

                return self::FAILURE;
            }

            // If serve process dies, stop everything
            if ($this->serveProcess !== null && ! $this->serveProcess->isRunning() && $this->buildProcess->isRunning()) {
                $this->error('Bun worker stopped unexpectedly.');
                $this->shutdown();

                return self::FAILURE;
            }

            usleep(200_000); // 200ms
        }

        return self::SUCCESS;
    }

    private function shutdown(): void
    {
        if ($this->serveProcess?->isRunning()) {
            $this->serveProcess->stop(5);
        }

        if ($this->buildProcess?->isRunning()) {
            $this->buildProcess->stop(5);
        }
    }

    private function trapSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (): void {
            $this->newLine();
            $this->info('Shutting down...');
            $this->shutdown();
            exit(0);
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    private function getBuildScript(): string
    {
        $vendorPath = base_path('vendor/larabun/lara-bun/resources/build-rsc-vite.ts');

        if (file_exists($vendorPath)) {
            return $vendorPath;
        }

        $packagePath = dirname(__DIR__, 2).'/resources/build-rsc-vite.ts';

        if (file_exists($packagePath)) {
            return $packagePath;
        }

        return $vendorPath;
    }

    /**
     * Environment for the Vite RSC build engine (build-rsc-vite.ts).
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function viteBuildEnv(array $extra = []): array
    {
        return array_merge(getenv(), [
            'LARA_BUN_PROJECT_ROOT' => base_path(),
            'BUN_RSC_SOURCE_DIR' => config('bun.rsc.source_dir'),
            'BUN_RSC_OUT_DIR' => base_path('bootstrap/rsc/vite'),
            'BUN_RSC_ASSETS_DIR' => config('bun.rsc.assets_dir'),
            'BUN_RSC_ASSETS_URL' => config('bun.rsc.assets_url'),
        ], $extra);
    }

    private function findBun(): ?string
    {
        return BunBinary::resolve();
    }
}
