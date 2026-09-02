<?php

namespace LaravelRsc\Console;

use Illuminate\Console\Command;
use LaravelRsc\Support\BuildEnvironment;
use LaravelRsc\Support\EnginePath;
use LaravelRsc\Support\RuntimeBinary;
use Symfony\Component\Process\Process;

class DevCommand extends Command
{
    protected $signature = 'rsc:dev {--socket= : Path to the Unix socket}';

    protected $description = 'Start the build watcher and Bun worker for development';

    /** @var Process|null */
    private $buildProcess = null;

    /** @var Process|null */
    private $serveProcess = null;

    public function handle(): int
    {
        $runtimePath = $this->findBun();

        if ($runtimePath === null) {
            $this->error(RuntimeBinary::runtime().' executable not found. Run: php artisan rsc:install (or set RSC_RUNTIME_BINARY to its path).');

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
            [$runtimePath, $buildScript],
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
        // source change; the worker restarts via rsc:serve --watch).
        $this->buildProcess = new Process(
            [$runtimePath, $buildScript],
            base_path(),
            $this->viteBuildEnv(['RSC_WATCH' => '1']),
        );
        $this->buildProcess->setTimeout(null);
        $this->buildProcess->start(fn ($type, $buffer) => $this->output->write($buffer));

        $this->info('Build watcher started.');

        // Step 3: Start rsc:serve --watch (skip if no bundle yet — watcher will trigger it)
        $socketOption = $this->option('socket') ? ['--socket='.$this->option('socket')] : [];
        $bundlePath = config('rsc.bundle', base_path('bootstrap/rsc/entry.rsc.js'));
        $canServe = $buildSucceeded && file_exists($bundlePath);

        if ($canServe) {
            $this->serveProcess = new Process(
                ['php', 'artisan', 'rsc:serve', '--watch', ...$socketOption],
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
                    ['php', 'artisan', 'rsc:serve', '--watch', ...$socketOption],
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
        return EnginePath::script('build-rsc-vite.ts') ?? 'build-rsc-vite.ts';
    }

    /**
     * Environment for the Vite RSC build engine (build-rsc-vite.ts).
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    /**
     * Always a development build: the watcher exists for the moment something
     * breaks, and a production bundle reports that as a minified error code.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function viteBuildEnv(array $extra = []): array
    {
        return BuildEnvironment::forVite(array_merge(['RSC_DEV' => '1'], $extra));
    }

    private function findBun(): ?string
    {
        return RuntimeBinary::resolve();
    }
}
