<?php

namespace LaravelRsc;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use LaravelRsc\Console\DevCommand;
use LaravelRsc\Console\InstallRuntimeCommand;
use LaravelRsc\Console\RscActionManifestCommand;
use LaravelRsc\Console\RscBuildCommand;
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

            if (is_dir($appDir)) {
                $scanner = new PageScanner($appDir);
                $scanner->scan();
                (new PageRouteRegistrar($this->app['router']))
                    ->register($scanner->getPages());
            }
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'lara-bun');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/rsc.php' => config_path('rsc.php'),
            ], 'rsc-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/lara-bun'),
            ], 'lara-bun-views');

            $this->commands([
                DevCommand::class,
                InstallRuntimeCommand::class,
                ServeCommand::class,
                RscActionManifestCommand::class,
                RscBuildCommand::class,
                RscPagesCommand::class,
                RscRouteManifestCommand::class,
            ]);
        }
    }
}
