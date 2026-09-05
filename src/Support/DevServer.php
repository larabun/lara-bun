<?php

namespace RscKit\Support;

/**
 * Whether `rsc:dev` is running, and where its Vite server is.
 *
 * The same mechanism Laravel already uses for Vite: the dev command writes a
 * "hot" file while it runs and removes it on the way out, and the web process
 * — a different process, with a different environment — reads that file rather
 * than being told.
 *
 * It matters because dev mode has to win over prerendered output. Most routes
 * in a real app are prerendered, so without this the very first page request
 * is answered from the last production build and never reaches the worker at
 * all: the app looks like it is running but no source edit has any effect.
 */
class DevServer
{
    public static function hotFile(): string
    {
        return base_path('bootstrap/rsc/hot');
    }

    /** Called by `rsc:dev` once its Vite server is up. */
    public static function start(string $origin): void
    {
        $file = self::hotFile();

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        file_put_contents($file, $origin);
    }

    /** Called by `rsc:dev` on the way out, however it exits. */
    public static function stop(): void
    {
        $file = self::hotFile();

        if (file_exists($file)) {
            unlink($file);
        }
    }

    public static function isActive(): bool
    {
        return file_exists(self::hotFile());
    }

    /** The dev server's origin, or null when it is not running. */
    public static function origin(): ?string
    {
        $file = self::hotFile();

        if (! file_exists($file)) {
            return null;
        }

        $origin = trim((string) file_get_contents($file));

        return $origin === '' ? null : $origin;
    }
}
