<?php

namespace LaravelRsc\Console;

use Illuminate\Console\Command;
use LaravelRsc\RuntimeBridge;
use LaravelRsc\Support\RuntimeBinary;

class ServeCommand extends Command
{
    protected $signature = 'rsc:serve {--socket= : Path to the Unix socket} {--watch : Auto-restart workers when RSC build output changes}';

    protected $description = 'Start the Bun bridge server';

    /** @var array<int, resource> */
    private array $processes = [];

    /** @var array<int, string> */
    private array $socketPaths = [];

    private int $lastBuildTime = 0;

    private int $consecutiveFailures = 0;

    private const MAX_CONSECUTIVE_FAILURES = 5;

    private string $transport = 'unix';

    private string $host = '127.0.0.1';

    private int $basePort = 7940;

    private int $workerCount = 1;

    public function handle(): int
    {
        $baseSocketPath = $this->option('socket') ?? config('rsc.socket_path', '/tmp/bun-bridge.sock');
        $functionsDir = config('rsc.functions_dir', resource_path('rsc-functions'));
        $workerCount = max(1, (int) config('rsc.workers', 1));
        $workerPath = realpath(__DIR__.'/../../resources/worker.ts');

        $this->workerCount = $workerCount;
        $this->transport = config('rsc.transport', 'unix') === 'tcp' ? 'tcp' : 'unix';
        $this->host = (string) config('rsc.host', '127.0.0.1');
        $this->basePort = (int) config('rsc.port', 7940);

        if ($workerPath === false) {
            $this->error('Worker not found in package resources');

            return self::FAILURE;
        }

        $hasFunctionsDir = is_dir($functionsDir);

        $runtimePath = $this->findBun();

        if ($runtimePath === null) {
            $this->error(RuntimeBinary::runtime().' executable not found. Run: php artisan rsc:install (or set RSC_RUNTIME_BINARY to its path).');

            return self::FAILURE;
        }

        $rscBundle = config('rsc.enabled')
            ? $this->detectRscBundle()
            : null;

        $entryPoints = collect(config('rsc.entry_points', []))
            ->filter()
            ->unique()
            ->implode(',');

        if (! $hasFunctionsDir && $entryPoints === '' && $rscBundle === null) {
            $this->error('Nothing to serve. Create a functions directory at: '.$functionsDir);
            $this->error('Or enable RSC via RSC_ENABLED=true and run: bun run build:rsc');

            return self::FAILURE;
        }

        if ($workerCount === 1) {
            return $this->serveSingle($baseSocketPath, $functionsDir, $hasFunctionsDir, $entryPoints, $workerPath, $runtimePath, $rscBundle);
        }

        $this->lastBuildTime = $this->getBuildTime();

        return $this->serveMultiple($baseSocketPath, $functionsDir, $hasFunctionsDir, $entryPoints, $workerPath, $runtimePath, $workerCount, $rscBundle);
    }

    private function serveSingle(
        string $socketPath,
        string $functionsDir,
        bool $hasFunctionsDir,
        string $entryPoints,
        string $workerPath,
        string $runtimePath,
        ?string $rscBundle = null,
    ): int {
        $this->socketPaths[0] = $this->transport === 'tcp'
            ? $this->host.':'.RuntimeBridge::tcpPorts($this->basePort, $this->workerCount, 0)['main']
            : RuntimeBridge::unixSocketPath($socketPath, 0);

        $this->info("Starting Bun bridge on {$this->socketPaths[0]}");
        $this->outputConfig($functionsDir, $hasFunctionsDir, $entryPoints, $workerPath, $runtimePath);

        $this->lastBuildTime = $this->getBuildTime();
        $this->trapSignals();

        $process = $this->spawnWorker($runtimePath, $workerPath, 0, $socketPath, $functionsDir, $hasFunctionsDir, $entryPoints, $rscBundle);

        if ($process === null) {
            $this->error('Failed to start the RSC worker process');

            return self::FAILURE;
        }

        $this->processes[0] = $process;

        if ($this->option('watch')) {
            $this->info('Watching for RSC build changes...');
        }

        // Supervise the worker in every mode (not just --watch): a crashed
        // worker is auto-restarted with backoff so a bare `rsc:serve` is
        // production-safe without an external process manager. This is process
        // liveness supervision (a cheap proc_get_status poll off the request
        // path) — filesystem watching for rebuilds only happens under --watch.
        return $this->monitorProcesses($runtimePath, $workerPath, $functionsDir, $hasFunctionsDir, $entryPoints, $rscBundle);
    }

    private function serveMultiple(
        string $baseSocketPath,
        string $functionsDir,
        bool $hasFunctionsDir,
        string $entryPoints,
        string $workerPath,
        string $runtimePath,
        int $workerCount,
        ?string $rscBundle = null,
    ): int {
        for ($i = 0; $i < $workerCount; $i++) {
            $this->socketPaths[$i] = $this->transport === 'tcp'
                ? $this->host.':'.RuntimeBridge::tcpPorts($this->basePort, $workerCount, $i)['main']
                : RuntimeBridge::unixSocketPath($baseSocketPath, $i);
        }

        $this->info("Starting RSC workers: {$workerCount} workers");
        $this->outputConfig($functionsDir, $hasFunctionsDir, $entryPoints, $workerPath, $runtimePath);

        foreach ($this->socketPaths as $i => $socketPath) {
            $this->line("  Worker {$i}: {$socketPath}");
        }

        $this->newLine();

        $this->trapSignals();

        for ($i = 0; $i < $workerCount; $i++) {
            $process = $this->spawnWorker($runtimePath, $workerPath, $i, $this->socketPaths[$i], $functionsDir, $hasFunctionsDir, $entryPoints, $rscBundle);

            if ($process === null) {
                $this->error("Failed to start worker {$i}");
                $this->shutdownAll();

                return self::FAILURE;
            }

            $this->processes[$i] = $process;
        }

        return $this->monitorProcesses($runtimePath, $workerPath, $functionsDir, $hasFunctionsDir, $entryPoints, $rscBundle);
    }

    /**
     * @return resource|null
     */
    private function spawnWorker(
        string $runtimePath,
        string $workerPath,
        int $index,
        string $socketPath,
        string $functionsDir,
        bool $hasFunctionsDir,
        string $entryPoints,
        ?string $rscBundle = null,
    ) {
        $env = $this->buildWorkerEnv($index, $socketPath, $functionsDir, $hasFunctionsDir, $entryPoints, $rscBundle);

        $process = proc_open(
            [$runtimePath, 'run', $workerPath],
            [
                0 => ['pipe', 'r'],
                1 => STDERR,
                2 => STDERR,
            ],
            $pipes,
            base_path(),
            $env,
        );

        if (is_resource($process)) {
            fclose($pipes[0]);

            return $process;
        }

        return null;
    }

    private function monitorProcesses(
        string $runtimePath,
        string $workerPath,
        string $functionsDir,
        bool $hasFunctionsDir,
        string $entryPoints,
        ?string $rscBundle = null,
    ): int {
        $watching = $this->option('watch');

        $count = count($this->processes);
        $this->info("Supervising {$count} worker(s) — auto-restart on crash.".($watching ? ' Watching for rebuilds.' : ''));

        while (true) {
            pcntl_signal_dispatch();

            if ($this->processes === []) {
                return self::SUCCESS;
            }

            if ($watching) {
                $currentBuildTime = $this->getBuildTime();

                if ($currentBuildTime > $this->lastBuildTime) {
                    $this->lastBuildTime = $currentBuildTime;
                    $this->consecutiveFailures = 0;
                    $this->newLine();
                    $this->info('Build change detected — restarting all workers...');

                    $this->shutdownAll();
                    usleep(500_000);

                    foreach ($this->socketPaths as $i => $socketPath) {
                        $process = $this->spawnWorker($runtimePath, $workerPath, $i, $socketPath, $functionsDir, $hasFunctionsDir, $entryPoints, $rscBundle);

                        if ($process === null) {
                            $this->error("Failed to restart worker {$i}, shutting down");
                            $this->shutdownAll();

                            return self::FAILURE;
                        }

                        $this->processes[$i] = $process;
                    }

                    $this->info('All workers restarted.');

                    continue;
                }
            }

            foreach ($this->processes as $i => $process) {
                $status = proc_get_status($process);

                if ($status['running']) {
                    continue;
                }

                proc_close($process);

                if ($status['exitcode'] !== 0) {
                    $this->consecutiveFailures++;

                    if ($this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                        $this->error("Workers crashed {$this->consecutiveFailures} times consecutively. Stopping.");
                        $this->error('Fix the error above and restart with: php artisan rsc:serve');
                        $this->shutdownAll();

                        return self::FAILURE;
                    }

                    $this->warn("Worker {$i} exited with code {$status['exitcode']}, restarting ({$this->consecutiveFailures}/".self::MAX_CONSECUTIVE_FAILURES.')...');

                    usleep(1_000_000 * $this->consecutiveFailures);

                    $newProcess = $this->spawnWorker($runtimePath, $workerPath, $i, $this->socketPaths[$i], $functionsDir, $hasFunctionsDir, $entryPoints, $rscBundle);

                    if ($newProcess === null) {
                        $this->error("Failed to restart worker {$i}, shutting down");
                        unset($this->processes[$i]);
                        $this->shutdownAll();

                        return self::FAILURE;
                    }

                    $this->processes[$i] = $newProcess;
                } else {
                    unset($this->processes[$i]);
                }
            }

            usleep(100_000); // 100ms
        }
    }

    private function trapSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (): void {
            $this->newLine();
            $this->info('Shutting down all workers...');
            $this->shutdownAll();
            exit(0);
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    private function shutdownAll(): void
    {
        foreach ($this->processes as $i => $process) {
            $status = proc_get_status($process);

            if ($status['running']) {
                posix_kill($status['pid'], SIGTERM);
            }

            proc_close($process);
            unset($this->processes[$i]);
        }

        foreach ($this->socketPaths as $socketPath) {
            if (file_exists($socketPath)) {
                @unlink($socketPath);
            }
        }
    }

    private function outputConfig(string $functionsDir, bool $hasFunctionsDir, string $entryPoints, string $workerPath, string $runtimePath): void
    {
        if ($hasFunctionsDir) {
            $this->line("Functions: {$functionsDir}");
        } else {
            $this->warn('Functions directory not found — skipping function discovery.');
        }

        if ($entryPoints !== '') {
            $this->line("Entry points: {$entryPoints}");
        }

        $this->line("Worker: {$workerPath}");
        $this->line("Using: {$runtimePath}");

        $this->warnIfPostMaxSizeTooLow();

        $this->line('Press Ctrl+C to stop');
    }

    private function warnIfPostMaxSizeTooLow(): void
    {
        $bodySizeLimit = RuntimeBridge::parseSize(config('rsc.body_size_limit', '1mb'));
        $postMaxSize = self::phpIniBytes('post_max_size');

        if ($postMaxSize > 0 && $postMaxSize < $bodySizeLimit) {
            $this->warn(
                "PHP's post_max_size (".ini_get('post_max_size').') is lower than body_size_limit ('
                .config('rsc.body_size_limit', '25mb').'). '
                .'PHP will silently reject server action payloads above '.ini_get('post_max_size').'.'
            );
        }
    }

    private static function phpIniBytes(string $key): int
    {
        $value = ini_get($key);

        if ($value === false || $value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $bytes = (int) $value;

        return match ($unit) {
            'g' => $bytes * 1024 * 1024 * 1024,
            'm' => $bytes * 1024 * 1024,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    /**
     * @return array<string, string>
     */
    private function buildWorkerEnv(int $index, string $socketPath, string $functionsDir, bool $hasFunctionsDir, string $entryPoints, ?string $rscBundle = null): array
    {
        // Start with inherited environment — proc_open replaces the entire
        // env when an array is passed, so we must include PATH, HOME, etc.
        $env = getenv();

        if ($this->transport === 'tcp') {
            $ports = RuntimeBridge::tcpPorts($this->basePort, $this->workerCount, $index);
            $env['RSC_TRANSPORT'] = 'tcp';
            $env['RSC_HOST'] = $this->host;
            $env['RSC_MAIN_PORT'] = (string) $ports['main'];
            $env['RSC_CB_PORT'] = (string) $ports['cb'];
        } else {
            $env['RSC_SOCKET'] = $socketPath;
        }

        $env['NODE_ENV'] = $this->option('watch') ? 'development' : 'production';

        if ($hasFunctionsDir) {
            $env['RSC_FUNCTIONS_DIR'] = $functionsDir;
        }

        if ($entryPoints !== '') {
            $env['RSC_ENTRY_POINTS'] = $entryPoints;
        }

        if ($rscBundle !== null) {
            $env['RSC_BUNDLE'] = $rscBundle;
        }

        $packageDir = realpath(__DIR__.'/../../');

        if ($packageDir !== false) {
            $env['RSC_PACKAGE_DIR'] = $packageDir;
        }

        $env['RSC_MAX_FRAME_SIZE'] = (string) RuntimeBridge::parseSize(
            config('rsc.body_size_limit', '1mb')
        );

        return $env;
    }

    private function detectRscBundle(): ?string
    {
        $configured = config('rsc.bundle');

        if ($configured && file_exists($configured)) {
            return $configured;
        }

        $this->warn('RSC bundle not found. Run: bun run build:rsc');

        return null;
    }

    private function getBuildTime(): int
    {
        $manifestPath = base_path('bootstrap/rsc/browser-manifest.json');

        clearstatcache(true, $manifestPath);

        if (file_exists($manifestPath)) {
            return (int) filemtime($manifestPath);
        }

        // Legacy fallback
        $chunksPath = base_path('bootstrap/rsc/browser-chunks.json');

        clearstatcache(true, $chunksPath);

        return file_exists($chunksPath) ? (int) filemtime($chunksPath) : 0;
    }

    private function findBun(): ?string
    {
        return RuntimeBinary::resolve();
    }
}
