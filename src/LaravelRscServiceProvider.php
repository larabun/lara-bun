<?php

namespace LaravelRsc;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use LaravelRsc\Console\DevCommand;
use LaravelRsc\Console\InstallRuntimeCommand;
use LaravelRsc\Console\RscActionManifestCommand;
use LaravelRsc\Console\RscBuildCommand;
use LaravelRsc\Console\RscExportCommand;
use LaravelRsc\Console\RscPagesCommand;
use LaravelRsc\Console\RscRouteManifestCommand;
use LaravelRsc\Console\ServeCommand;

class LaravelRscServiceProvider extends ServiceProvider
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

        return Vite::cspNonce();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rsc.php', 'rsc');

        $this->app->singleton(RuntimeBridge::class);

        // Scoped, not singleton: what an action invalidated belongs to the
        // request that ran it. Under a persistent runtime a singleton would
        // carry one request's marks into the next.
        $this->app->scoped(Revalidation::class);

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
        if (config('rsc.enabled')) {
            // Auto-register RscMiddleware in the web middleware group
            // so it runs on all RSC page routes (sets Vary, Cache-Control).
            $this->app['router']->pushMiddlewareToGroup('web', RscMiddleware::class);

            Route::post('/_rsc/action', RscActionController::class)
                ->middleware('web');

            $appDir = config('rsc.source_dir').'/app';

            // Read rather than walked: the build already found the route tree
            // and wrote it down. Routing therefore needs a build — which was
            // already true for the bundle, and is why a missing manifest names
            // the command rather than registering nothing.
            if (is_dir($appDir)) {
                (new PageRouteRegistrar($this->app['router']))
                    ->register(RouteManifest::forApp()->pages());
            }
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/rsc.php' => config_path('rsc.php'),
            ], 'rsc-config');

            $this->commands([
                DevCommand::class,
                InstallRuntimeCommand::class,
                ServeCommand::class,
                RscActionManifestCommand::class,
                RscBuildCommand::class,
                RscExportCommand::class,
                RscPagesCommand::class,
                RscRouteManifestCommand::class,
            ]);
        }
    }
}
