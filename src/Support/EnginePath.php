<?php

namespace LaravelRsc\Support;

/**
 * Locates the JavaScript engine — the Vite plugin, the build CLI and the worker.
 *
 * The engine is published to npm as `@rsc-router/core` and is backend-agnostic;
 * this package is one host for it, and no longer carries a copy of it. An app
 * installs it like any other dependency, which is also the copy its own Vite
 * config resolves against — one engine, not one per install path that can
 * drift from the other.
 *
 * Every caller resolves through here. Three commands used to rebuild the path
 * themselves and had already drifted — two still pointed at `larabun/lara-bun`,
 * a package name that no longer exists.
 */
class EnginePath
{
    public const PACKAGE = '@rsc-router/core';

    /**
     * Where the engine's files sit inside the package.
     *
     * The npm package publishes its source under src/, so the directory
     * holding vite.ts and js/ is not the package root. Pointing at the root
     * finds nothing, and finding nothing reads as "not installed".
     */
    public const SOURCE_DIR = 'src';

    /**
     * Absolute path to an engine script, or null when it cannot be found.
     */
    public static function script(string $file): ?string
    {
        foreach (self::roots() as $root) {
            $path = $root.'/'.$file;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The engine's directory — the one holding vite.ts, worker.ts and js/.
     *
     * This is what the Vite plugin means by its package dir, and it is a
     * directory rather than the Composer package root: `js/Link.tsx` is
     * resolved against it. Deriving it at a call site is how `rsc:serve` came
     * to pass the package root while the build passed this, a disagreement
     * nothing noticed until the plugin started running inside the worker.
     */
    public static function directory(): ?string
    {
        foreach (self::roots() as $root) {
            if (is_dir($root)) {
                return $root;
            }
        }

        return null;
    }

    /**
     * Directories to look in, most specific first.
     *
     * @return list<string>
     */
    public static function roots(): array
    {
        $roots = [];

        $override = getenv('RSC_ENGINE_DIR');

        if (is_string($override) && $override !== '') {
            $roots[] = rtrim($override, '/');
        }

        if (function_exists('base_path')) {
            $roots[] = base_path('node_modules/'.self::PACKAGE.'/'.self::SOURCE_DIR);
        }

        return $roots;
    }
}
