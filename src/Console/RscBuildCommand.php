<?php

namespace LaravelRsc\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use LaravelRsc\PrerenderService;
use LaravelRsc\Support\ActionManifest;
use LaravelRsc\Support\BuildEnvironment;
use LaravelRsc\Support\EnginePath;
use LaravelRsc\Support\InterceptManifest;
use LaravelRsc\Support\RuntimeBinary;
use Symfony\Component\Process\Process;

class RscBuildCommand extends Command
{
    protected $signature = 'rsc:build {--clean : Remove existing static files} {--skip-prerender : Build bundles only} {--skip-bundle : Pre-render only (assumes bundles are already built)}
        {--dev : Build against React\'s development bundle, so errors are readable}';

    protected $description = 'Build RSC bundles and pre-render static pages';

    public function handle(PrerenderService $prerender): int
    {
        if (! config('rsc.enabled')) {
            $this->error('RSC is not enabled. Set RSC_ENABLED=true in your .env.');

            return self::FAILURE;
        }

        // Step 1: Bundle via Bun (unless --skip-bundle)
        if (! $this->option('skip-bundle')) {
            $this->info('Building RSC bundles...');
            $this->newLine();

            $runtime = RuntimeBinary::resolve();

            if ($runtime === null) {
                $this->error(RuntimeBinary::runtime().' executable not found. Run: php artisan rsc:install (or set RSC_RUNTIME_BINARY to its path).');

                return self::FAILURE;
            }

            $this->writeServerActions();
            $this->writeInterceptManifest();

            $bundleProcess = new Process(
                [$runtime, $this->getBuildScript('build-rsc-vite.ts')],
                base_path(),
                BuildEnvironment::forVite($this->option('dev') ? ['RSC_DEV' => '1'] : []),
            );
            $bundleProcess->setTimeout(120);
            $bundleProcess->run(fn ($type, $buffer) => $this->output->write($buffer));

            if (! $bundleProcess->isSuccessful()) {
                $this->error('Bundle build failed.');

                return self::FAILURE;
            }

            $this->newLine();
        }

        if ($this->option('skip-prerender')) {
            $this->info('Bundles built successfully (prerender skipped).');

            return self::SUCCESS;
        }

        // Step 2: Clean static files if requested
        $outputPath = config('rsc.static_path', storage_path('framework/rsc-static'));

        if ($this->option('clean') && is_dir($outputPath)) {
            File::deleteDirectory($outputPath);
        }

        // Step 3: Start fresh Bun worker (force restart since bundles just changed)
        $bunProcess = $prerender->ensureBunWorker(forceRestart: ! $this->option('skip-bundle'));

        if ($bunProcess === false) {
            $this->error('Bun worker failed to start.');

            return self::FAILURE;
        }

        // Step 4: Discover ALL RSC routes
        $routes = $prerender->discoverRscRoutes();

        if ($routes->isEmpty()) {
            $this->warn('No RSC routes found.');

            if ($bunProcess instanceof Process) {
                $bunProcess->stop(5);
            }

            return self::SUCCESS;
        }

        // Step 5: Render each route, collect results
        $results = $this->prerenderRoutes($routes, $prerender, $outputPath);

        // Step 6: Stop worker if we started it
        if ($bunProcess instanceof Process) {
            $bunProcess->stop(5);
        }

        // Step 7: Print route summary
        $this->printRouteSummary($results);

        $hasErrors = collect($results)->contains('type', 'error');

        if ($hasErrors) {
            $this->newLine();
            $this->error('Build failed. Fix the errors above and try again.');
            $this->line('Hint: async content using php() must be wrapped in <Suspense> or provide a loading.tsx.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Publish the intercepted URL patterns for the client router.
     *
     * Without them every intercepted link falls through to a full-page
     * navigation, which is what the modal demo was doing after the migration.
     */
    private function writeInterceptManifest(): void
    {
        $target = base_path('bootstrap/rsc/vite/intercept-manifest.json');
        $entries = InterceptManifest::discover(rtrim((string) config('rsc.source_dir'), '/').'/app');

        File::ensureDirectoryExists(dirname($target));
        File::put($target, json_encode($entries, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        if ($entries !== []) {
            $this->line('Published '.count($entries).' intercept route(s) → '.$target);
        }
    }

    /**
     * Regenerate the "use server" module wrapping the app's PHP actions.
     *
     * The client imports these as ordinary async functions, so they have to be
     * rewritten whenever the actions change — or whenever the host global is
     * renamed, which is how they were last left calling a global that no longer
     * existed. Written before the bundle build so Vite picks up the new file.
     */
    private function writeServerActions(): void
    {
        $sourceDir = rtrim((string) config('rsc.source_dir'), '/');
        $target = $sourceDir.'/server-actions.generated.ts';
        $hostGlobal = (string) config('rsc.host_global', 'rpc');
        $actions = ActionManifest::discover();

        // The host global is installed at runtime, so nothing in app source
        // declares it and a typecheck cannot see it — which is how a renamed
        // global survived a clean build. Always written, actions or not.
        File::ensureDirectoryExists($sourceDir);
        File::put($sourceDir.'/rsc-env.d.ts', ActionManifest::renderTypes($hostGlobal));

        // The engine's own ambient types (Metadata, GenerateMetadata) live with
        // the engine and are copied where the app's typechecker will see them.
        // They are deliberately a separate file: this one is the engine's, the
        // one above is generated from this host's configuration.
        $engineTypes = EnginePath::script('types.d.ts');

        if ($engineTypes !== null) {
            File::copy($engineTypes, $sourceDir.'/rsc-types.d.ts');
        }

        if ($actions === []) {
            if (File::exists($target)) {
                File::delete($target);
                $this->line("Removed stale: {$target}");
            }

            return;
        }

        File::ensureDirectoryExists(dirname($target));
        File::put($target, ActionManifest::render($actions, $hostGlobal));

        $this->line('Generated '.count($actions).' server action(s) → '.$target);
    }

    /**
     * @param  Collection<int, Route>  $routes
     * @return list<array{url: string, uri: string, component: string, type: string, reason: string|null, generatedPaths: list<string>}>
     */
    private function prerenderRoutes(Collection $routes, PrerenderService $prerender, string $outputPath): array
    {
        $results = [];

        foreach ($routes as $route) {
            $component = $route->defaults['_rsc_component'];
            $uri = '/'.ltrim($route->uri(), '/');
            $isParameterized = str_contains($route->uri(), '{');

            $urls = $prerender->resolveUrls($route);

            // Parameterized without staticPaths — PPR shell with fallback
            if ($isParameterized && empty($urls)) {
                try {
                    $result = $prerender->prerenderPprShell($uri, $route, $outputPath);

                    $results[] = [
                        'url' => $uri,
                        'uri' => $uri,
                        'component' => $component,
                        'type' => 'ppr',
                        'reason' => 'dynamic params',
                        'generatedPaths' => [],
                    ];
                } catch (\Throwable $e) {
                    $this->error("  PPR shell failed {$uri}: {$e->getMessage()}");

                    $results[] = [
                        'url' => $uri,
                        'uri' => $uri,
                        'component' => $component,
                        'type' => 'error',
                        'reason' => $e->getMessage(),
                        'generatedPaths' => [],
                    ];
                }

                continue;
            }

            // Parameterized with staticPaths — prerender each path
            if ($isParameterized && ! empty($urls)) {
                $generatedPaths = [];

                foreach ($urls as $url) {
                    try {
                        $result = $this->prerenderSingleUrl($url, $route, $outputPath, $prerender);
                        $generatedPaths[] = $url;
                    } catch (\Throwable $e) {
                        $this->error("  Failed {$url}: {$e->getMessage()}");
                    }
                }

                $results[] = [
                    'url' => $uri,
                    'uri' => $uri,
                    'component' => $component,
                    'type' => 'ssg',
                    'reason' => null,
                    'generatedPaths' => $generatedPaths,
                ];

                continue;
            }

            // Non-parameterized — prerender
            $url = $urls[0] ?? $uri;

            try {
                $result = $this->prerenderSingleUrl($url, $route, $outputPath, $prerender);

                $results[] = [
                    'url' => $url,
                    'uri' => $uri,
                    'component' => $component,
                    'type' => $result['type'],
                    'reason' => $result['reason'],
                    'generatedPaths' => [],
                ];
            } catch (\Throwable $e) {
                $this->error("  Failed {$url}: {$e->getMessage()}");

                $results[] = [
                    'url' => $url,
                    'uri' => $uri,
                    'component' => $component,
                    'type' => 'error',
                    'reason' => $e->getMessage(),
                    'generatedPaths' => [],
                ];
            }
        }

        return $results;
    }

    /**
     * @param  list<array{url: string, uri: string, component: string, type: string, reason: string|null, generatedPaths: list<string>}>  $results
     */
    /**
     * Prerender a single URL — static if no dynamic APIs, PPR otherwise.
     *
     * @return array{type: string, reason: string|null}
     */
    private function prerenderSingleUrl(string $url, Route $route, string $outputPath, PrerenderService $prerender): array
    {
        $result = $prerender->prerenderUrl($url, $route, $outputPath);

        // No dynamic APIs → fully static, already saved
        if ($result['type'] === 'static') {
            return $result;
        }

        // Uses dynamic APIs → PPR (cached shell + fresh Flight per request)
        return $prerender->prerenderPpr($url, $route, $outputPath);
    }

    private function printRouteSummary(array $results): void
    {
        $this->newLine();
        $this->line('<fg=white;options=bold>Route (app)</>');
        $this->line(str_repeat("\u{2500}", 45));

        $staticCount = 0;
        $ssgCount = 0;
        $dynamicCount = 0;
        $pprCount = 0;

        foreach ($results as $result) {
            $uri = $result['uri'];
            $type = $result['type'];
            $reason = $result['reason'];

            if ($type === 'static') {
                $staticCount++;
                $icon = "\u{25CB}";
                $label = 'Static';

                if ($reason === null) {
                    $this->line("<fg=green>{$icon}</>  {$uri}  <fg=gray>{$label}</>");
                } else {
                    $this->line("<fg=green>{$icon}</>  {$uri}  <fg=gray>{$label} ({$reason})</>");
                }
            } elseif ($type === 'ssg') {
                $ssgCount++;
                $icon = "\u{25CF}";
                $pathCount = count($result['generatedPaths']);
                $label = "SSG ({$pathCount} ".($pathCount === 1 ? 'path' : 'paths').')';
                $this->line("<fg=blue>{$icon}</>  {$uri}  <fg=gray>{$label}</>");

                $paths = $result['generatedPaths'];
                $lastIndex = count($paths) - 1;

                foreach ($paths as $i => $path) {
                    $connector = $i === $lastIndex ? "\u{2514}" : "\u{251C}";
                    $this->line("   {$connector} {$path}");
                }
            } elseif ($type === 'ppr') {
                $pprCount++;
                $icon = "\u{25D4}";
                $pathCount = count($result['generatedPaths']);
                $label = $pathCount > 1 ? "PPR ({$pathCount} paths)" : 'PPR';

                if ($reason !== null) {
                    $this->line("<fg=magenta>{$icon}</>  {$uri}  <fg=gray>{$label} ({$reason})</>");
                } else {
                    $this->line("<fg=magenta>{$icon}</>  {$uri}  <fg=gray>{$label}</>");
                }

                if ($pathCount > 1) {
                    $paths = $result['generatedPaths'];
                    $lastIndex = count($paths) - 1;

                    foreach ($paths as $i => $path) {
                        $connector = $i === $lastIndex ? "\u{2514}" : "\u{251C}";
                        $this->line("   {$connector} {$path}");
                    }
                }
            } elseif ($type === 'dynamic') {
                $dynamicCount++;
                $icon = "\u{03BB}";
                $label = 'Dynamic';

                if ($reason !== null) {
                    $this->line("<fg=yellow>{$icon}</>  {$uri}  <fg=gray>{$label} ({$reason})</>");
                } else {
                    $this->line("<fg=yellow>{$icon}</>  {$uri}  <fg=gray>{$label}</>");
                }
            } elseif ($type === 'error') {
                $dynamicCount++;
                $this->line("<fg=red>!</>  {$uri}  <fg=red>Error: {$reason}</>");
            }
        }

        $this->newLine();
        $this->line("<fg=green>\u{25CB}</>  Static    prerendered as static HTML");
        $this->line("<fg=blue>\u{25CF}</>  SSG       static with generated params");
        $this->line("<fg=magenta>\u{25D4}</>  PPR       static shell + streamed dynamic");
        $this->line("<fg=yellow>\u{03BB}</>  Dynamic   server-rendered on demand");
        $this->newLine();

        $parts = [];

        if ($staticCount > 0) {
            $parts[] = "{$staticCount} static";
        }

        if ($ssgCount > 0) {
            $parts[] = "{$ssgCount} SSG";
        }

        if ($pprCount > 0) {
            $parts[] = "{$pprCount} PPR";
        }

        if ($dynamicCount > 0) {
            $parts[] = "{$dynamicCount} dynamic";
        }

        $this->info(implode(', ', $parts));
    }

    private function getBuildScript(string $file = 'build-rsc-vite.ts'): string
    {
        return EnginePath::script($file) ?? $file;
    }
}
