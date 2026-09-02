<?php

namespace LaravelRsc\Console;

use Illuminate\Console\Command;
use LaravelRsc\Support\BuildEnvironment;
use LaravelRsc\Support\DevServer;
use LaravelRsc\Support\HostManifests;
use LaravelRsc\Support\RuntimeBinary;
use Symfony\Component\Process\Process;

/**
 * Development mode: the worker renders from source through a Vite dev server.
 *
 * There is no bundle and no watcher. The worker imports the generated entry
 * through Vite's runnable `rsc` environment, so an edit is picked up on the
 * next request rather than after a rebuild.
 *
 * The app keeps its normal development URL — PHP still serves every page. The
 * dev server only answers module requests, which is the arrangement Laravel
 * developers already have with Vite. Because @vitejs/plugin-rsc emits those
 * URLs root-relative and no Vite setting moves them, the SSR entry rewrites
 * them onto the dev origin; see resources/devUrls.ts.
 */
class DevCommand extends Command
{
    protected $signature = 'rsc:dev
        {--socket= : Path to the Unix socket}
        {--port=5173 : Port for the Vite dev server}';

    protected $description = 'Serve RSC from source through a Vite dev server';

    private ?Process $serveProcess = null;

    public function handle(): int
    {
        if (! config('rsc.enabled')) {
            $this->error('RSC is not enabled. Set RSC_ENABLED=true in your .env.');

            return self::FAILURE;
        }

        if (RuntimeBinary::resolve() === null) {
            $this->error(RuntimeBinary::runtime().' executable not found. Run: php artisan rsc:install (or set RSC_RUNTIME_BINARY to its path).');

            return self::FAILURE;
        }

        $config = $this->viteConfigFile();

        if ($config === null) {
            $this->error('No Vite config found. Create vite.config.ts with rscRoutes() in its plugins.');

            return self::FAILURE;
        }

        // PHP owns route and action discovery, so these have to exist before
        // the JavaScript half runs. Dev mode skips the bundle build, which is
        // exactly how it would come to skip these too.
        foreach (HostManifests::write() as $note) {
            $this->line($note);
        }

        $this->trapSignals();

        $port = (int) $this->option('port');
        $origin = "http://localhost:{$port}";
        $socketOption = $this->option('socket') ? ['--socket='.$this->option('socket')] : [];

        $this->serveProcess = new Process(
            ['php', 'artisan', 'rsc:serve', '--watch', ...$socketOption],
            base_path(),
            $this->devEnv($config, $port),
        );
        $this->serveProcess->setTimeout(null);
        $this->serveProcess->start(fn ($type, $buffer) => $this->output->write($buffer));

        // Tells the web process — which has none of this environment — to stop
        // answering from prerendered output and go to the worker instead.
        DevServer::start($origin);

        $this->newLine();
        $this->info("Vite dev server on http://localhost:{$port} — serving RSC from source.");
        $this->info('Open the app at its usual URL. Press Ctrl+C to stop.');
        $this->newLine();

        while ($this->serveProcess->isRunning()) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            usleep(200_000);
        }

        // The worker died on its own; a stale hot file would leave every
        // request going to a worker that is not there.
        DevServer::stop();

        return $this->serveProcess->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Environment for a worker that renders from source.
     *
     * @return array<string, string>
     */
    private function devEnv(string $config, int $port): array
    {
        $origin = "http://localhost:{$port}";

        return BuildEnvironment::forVite([
            // Presence of this is what puts the worker in dev mode.
            'RSC_DEV_CONFIG' => $config,
            'RSC_DEV_PORT' => (string) $port,
            // Baked into the SSR entry so it can point the browser at Vite.
            'RSC_DEV_ORIGIN' => $origin,
            'RSC_DEV' => '1',
            // No built assets exist in dev, so Vite serves from the root. The
            // production base would otherwise prefix every dev module URL
            // (/build/rsc-vite/@id/...), which is neither where Vite serves
            // them nor what the rewrite looks for.
            'RSC_ASSETS_URL' => '/',
            // One dev server, so one worker — a second would find the port
            // taken and fail on strictPort.
            'RSC_WORKERS' => '1',
        ]);
    }

    /**
     * The config Vite should run, resolved the way the build resolves it.
     *
     * vite.rsc.config.* wins so an app whose root config drives another asset
     * pipeline can keep the two separate.
     */
    private function viteConfigFile(): ?string
    {
        $names = [
            'vite.rsc.config.ts', 'vite.rsc.config.mts', 'vite.rsc.config.js', 'vite.rsc.config.mjs',
            'vite.config.ts', 'vite.config.mts', 'vite.config.js', 'vite.config.mjs',
        ];

        foreach ($names as $name) {
            if (file_exists(base_path($name))) {
                return base_path($name);
            }
        }

        return null;
    }

    private function trapSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (): void {
            $this->newLine();
            $this->info('Shutting down...');

            if ($this->serveProcess?->isRunning()) {
                $this->serveProcess->stop(5);
            }

            DevServer::stop();

            exit(0);
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }
}
