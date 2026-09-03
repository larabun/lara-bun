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
 *
 * Where the app tree lives is no longer among them. It is declared in the vite
 * config, because everything that reads it is the build.
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
            'RSC_OUT_DIR' => base_path('bootstrap/rsc/vite'),
            'RSC_ASSETS_DIR' => config('rsc.assets_dir'),
            'RSC_ASSETS_URL' => config('rsc.assets_url'),
            'RSC_HOST_GLOBAL' => config('rsc.host_global', 'rpc'),
            // The package's own name, not a setting: the alias only exists so a
            // vendored copy can be imported by the name it publishes under.
            'RSC_PACKAGE_ALIAS' => EnginePath::PACKAGE,
            'RSC_ROUTE_CONFIG_FILE' => 'route.php',
            'RSC_ROUTE_CONFIG_PATTERN' => 'props\s*\(\s*(fn|function)\s*\(',
            // Discovery is reflection over the app's own classes, so it stays
            // here; the build renders the stubs, because they have to land
            // beside the app's source and that path is the build's to know.
            'RSC_HOST_ACTIONS' => json_encode(ActionManifest::discover(), JSON_THROW_ON_ERROR),
        ], $extra);
    }
}
