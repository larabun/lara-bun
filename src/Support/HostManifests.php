<?php

namespace LaravelRsc\Support;

use Illuminate\Support\Facades\File;
use LaravelRsc\Console\DevCommand;
use LaravelRsc\Console\RscBuildCommand;

/**
 * The two artifacts PHP generates before the JavaScript half runs.
 *
 * PHP owns route and action discovery, so the client cannot discover either on
 * its own — it needs the result up front. Neither failure is visible at build
 * time: a stale actions module calls a global that no longer exists, and an
 * empty intercept manifest silently turns every intercepted link into a
 * full-page navigation. So both are rewritten on every run.
 *
 * Shared by `rsc:build` and `rsc:dev`. Dev mode skips the bundle build
 * entirely, which is exactly how it would come to skip these too.
 *
 * @see RscBuildCommand
 * @see DevCommand
 */
class HostManifests
{
    /**
     * Write both manifests.
     *
     * @return list<string> Human-readable notes on what was written.
     */
    public static function write(): array
    {
        return array_values(array_filter([
            self::writeServerActions(),
            self::writeInterceptManifest(),
        ]));
    }

    /** Routes the client router should intercept rather than navigate to. */
    public static function writeInterceptManifest(): ?string
    {
        $target = base_path('bootstrap/rsc/vite/intercept-manifest.json');
        $entries = InterceptManifest::discover(rtrim((string) config('rsc.source_dir'), '/').'/app');

        File::ensureDirectoryExists(dirname($target));
        File::put($target, json_encode($entries, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $entries === []
            ? null
            : 'Published '.count($entries).' intercept route(s) → '.$target;
    }

    /**
     * Regenerate the "use server" module wrapping the app's PHP actions.
     *
     * The client imports these as ordinary async functions, so they have to be
     * rewritten whenever the actions change — or whenever the host global is
     * renamed, which is how they were last left calling a global that no longer
     * existed.
     */
    public static function writeServerActions(): ?string
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

                return "Removed stale: {$target}";
            }

            return null;
        }

        File::ensureDirectoryExists(dirname($target));
        File::put($target, ActionManifest::render($actions, $hostGlobal));

        return 'Generated '.count($actions).' server action(s) → '.$target;
    }
}
