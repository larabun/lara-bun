<?php

namespace LaravelRsc\Support;

/**
 * The environment the Vite build runs under.
 *
 * The plugin assumes no backend, so Laravel's conventions — where the app tree
 * lives, the import prefix for the client runtime, the file that marks a route
 * dynamic — are passed in. Both the build command and the dev watcher have to
 * pass the same set: when they were written out separately the watcher was
 * missing several, so `rsc:dev` built without the alias or route.php detection
 * and only `rsc:build` was correct.
 */
class BuildEnvironment
{
    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public static function forVite(array $extra = []): array
    {
        return array_merge(getenv(), [
            'RSC_PROJECT_ROOT' => base_path(),
            'RSC_SOURCE_DIR' => config('rsc.source_dir'),
            'RSC_OUT_DIR' => base_path('bootstrap/rsc/vite'),
            'RSC_ASSETS_DIR' => config('rsc.assets_dir'),
            'RSC_ASSETS_URL' => config('rsc.assets_url'),
            'RSC_HOST_GLOBAL' => config('rsc.host_global', 'rpc'),
            // The package's own name, not a setting: the alias only exists so a
            // vendored copy can be imported by the name it publishes under.
            'RSC_PACKAGE_ALIAS' => EnginePath::PACKAGE,
            'RSC_ROUTE_CONFIG_FILE' => 'route.php',
            'RSC_ROUTE_CONFIG_PATTERN' => 'props\s*\(\s*(fn|function)\s*\(',
        ], $extra);
    }
}
