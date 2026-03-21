<?php

namespace LaraBun;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use LaraBun\Console\BunDevCommand;
use LaraBun\Console\BunServeCommand;
use LaraBun\Console\RscActionManifestCommand;
use LaraBun\Console\RscBuildCommand;
use LaraBun\Console\RscPagesCommand;
use LaraBun\Console\RscRouteManifestCommand;
use LaraBun\Rsc\CallableRegistry;
use LaraBun\Rsc\PageRouteRegistrar;
use LaraBun\Rsc\PageScanner;
use LaraBun\Rsc\RscActionController;

class BunServiceProvider extends ServiceProvider
{
    /**
     * Get the CSP nonce for inline script tags.
     * Prioritises spatie/laravel-csp's nonce (works with any generator),
     * then falls back to Vite's nonce (set via Vite::useCspNonce()).
     */
    public static function cspNonce(): ?string
    {
        // spatie/laravel-csp binds the nonce in the container
        try {
            $nonce = app('csp-nonce');
            if ($nonce) {
                return $nonce;
            }
        } catch (\Throwable) {
            // not bound
        }

        return \Illuminate\Support\Facades\Vite::cspNonce();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bun.php', 'bun');

        $this->app->singleton(BunBridge::class);

        $this->app->singleton(CallableRegistry::class, function ($app) {
            $registry = new CallableRegistry($app);

            $directory = app_path('Rsc');

            if (is_dir($directory)) {
                $registry->discoverFrom($directory);
            }

            $actionsDir = app_path('Rsc/Actions');

            if (is_dir($actionsDir)) {
                $registry->discoverFrom($actionsDir);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        if (config('bun.ssr.enabled') && interface_exists(\Inertia\Ssr\Gateway::class)) {
            $this->app->singleton(\Inertia\Ssr\Gateway::class, Ssr\BunSsrGateway::class);
        }

        if (config('bun.rsc.enabled')) {
            Route::post('/_rsc/action', RscActionController::class)
                ->middleware('web');

            $appDir = config('bun.rsc.source_dir').'/app';

            if (is_dir($appDir)) {
                $scanner = new PageScanner($appDir);
                $scanner->scan();
                (new PageRouteRegistrar($this->app['router']))
                    ->register($scanner->getPages());
            }
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'lara-bun');
        $this->registerBladeDirectives();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bun.php' => config_path('bun.php'),
            ], 'lara-bun-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/lara-bun'),
            ], 'lara-bun-views');

            $this->commands([
                BunDevCommand::class,
                BunServeCommand::class,
                RscActionManifestCommand::class,
                RscBuildCommand::class,
                RscPagesCommand::class,
                RscRouteManifestCommand::class,
            ]);
        }
    }

    private function registerBladeDirectives(): void
    {
        Blade::directive('rscScripts', function (string $expression) {
            return "<?php echo \LaraBun\BunServiceProvider::renderRscScripts({$expression}); ?>";
        });

        Blade::directive('rscHead', function () {
            return "<?php echo \LaraBun\BunServiceProvider::renderRscHead(); ?>";
        });

        Blade::directive('rscNonce', function () {
            return '<?php echo \LaraBun\BunServiceProvider::cspNonce() ? \'nonce="\' . e(\LaraBun\BunServiceProvider::cspNonce()) . \'"\' : \'\'; ?>';
        });
    }

    /**
     * Render <link rel="modulepreload"> hints for the hydrate entry, shared chunks,
     * and component chunks. Place @rscHead in your <head> to eliminate critical
     * request chains — the browser fetches all JS in parallel on first paint.
     */
    public static function renderRscHead(): HtmlString
    {
        $buildDir = public_path('build/rsc');

        if (! is_dir($buildDir)) {
            return new HtmlString('');
        }

        $tags = '';

        foreach (glob("{$buildDir}/entry.hydrate-*.js") as $entry) {
            $tags .= "\n    <link rel=\"modulepreload\" href=\"/build/rsc/".basename($entry).'">';
        }

        foreach (glob("{$buildDir}/chunk-*.js") as $chunk) {
            $tags .= "\n    <link rel=\"modulepreload\" href=\"/build/rsc/".basename($chunk).'">';
        }

        foreach (glob("{$buildDir}/_register_*.js") as $module) {
            $tags .= "\n    <link rel=\"modulepreload\" href=\"/build/rsc/".basename($module).'">';
        }

        return new HtmlString($tags);
    }

    /**
     * Render the inline script block and module tags needed to hydrate RSC client components.
     *
     * Accepts either a structured browser manifest (with entry/shared/modules) or
     * a flat array of chunk URLs for backwards compatibility.
     *
     * @param  string  $rscPayload  The Flight payload string
     * @param  array<string, mixed>|string[]  $browserManifest  Structured manifest or flat chunk array
     */
    public static function renderRscScripts(string $rscPayload, array $browserManifest): HtmlString
    {
        if ($browserManifest === []) {
            return new HtmlString('');
        }

        // Support both structured manifest and legacy flat array
        if (isset($browserManifest['entry'])) {
            $entry = $browserManifest['entry'];
            $shared = $browserManifest['shared'] ?? [];
        } else {
            // Legacy flat array — first chunk is entry, rest are shared
            $entry = $browserManifest[0] ?? '';
            $shared = array_slice($browserManifest, 1);
        }

        $encodedPayload = json_encode($rscPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $nonce = static::cspNonce();
        $nonceAttr = $nonce ? ' nonce="'.e($nonce).'"' : '';

        // Only emit script tags for shared chunks + hydrate entry.
        // Component chunks are loaded on demand by Flight via __webpack_chunk_load__.
        $chunkTags = '';
        foreach ($shared as $chunk) {
            $escaped = e($chunk);
            $chunkTags .= "\n    <script type=\"module\" src=\"{$escaped}\"{$nonceAttr}></script>";
        }

        $escapedEntry = e($entry);
        $chunkTags .= "\n    <script type=\"module\" src=\"{$escapedEntry}\"{$nonceAttr}></script>";

        $hmrScript = '';
        $devFlagPath = storage_path('framework/rsc-dev');

        if (file_exists($devFlagPath)) {
            $hmrPort = (int) (file_get_contents($devFlagPath) ?: 3001);
            $hmrScript = <<<HMRJS

    <script{$nonceAttr}>
        (function() {
            var ws, timer;
            function connect() {
                ws = new WebSocket('ws://localhost:{$hmrPort}');
                ws.onmessage = function(e) {
                    if (e.data === 'reload' && window.__rsc_navigate) {
                        var scrollX = window.scrollX, scrollY = window.scrollY;
                        window.__rsc_navigate(location.pathname + location.search, { replace: true });
                        requestAnimationFrame(function() { window.scrollTo(scrollX, scrollY); });
                    } else {
                        location.reload();
                    }
                };
                ws.onclose = function() { timer = setTimeout(connect, 1000); };
            }
            connect();
        })();
    </script>
HMRJS;
        }

        return new HtmlString(<<<HTML
    <script{$nonceAttr}>
        window.__RSC_PAYLOAD__ = {$encodedPayload};
        window.__RSC_MODULES__ = {};
        window.__webpack_require__ = function(id) { return window.__RSC_MODULES__[id]; };
        window.__webpack_chunk_load__ = function(chunkUrl) {
            return new Promise(function(resolve, reject) {
                var existing = document.querySelector('script[src="' + chunkUrl + '"]');
                if (existing) { resolve(); return; }
                var script = document.createElement('script');
                script.type = 'module';
                script.src = chunkUrl;
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        };
    </script>{$chunkTags}{$hmrScript}
HTML);
    }
}
