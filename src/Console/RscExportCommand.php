<?php

namespace LaravelRsc\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Write the prerendered site out as files a static host can serve.
 *
 * `rsc:build` already renders every static route to HTML; this arranges those
 * files as a browsable tree and puts the Flight payloads beside them.
 *
 * The payloads need addresses of their own. Normally a page and its payload
 * share a url and are told apart by the X-RSC header, which a host that serves
 * files cannot act on — ask for the page with the header and you get the page.
 * So each page also gets `index.rsc`, and a client built for export asks for
 * that instead.
 *
 * Routes that need a server at request time cannot be exported at all, and the
 * command refuses rather than writing a site that is quietly wrong: a PPR
 * route would ship its shell and never fill it in.
 */
class RscExportCommand extends Command
{
    protected $signature = 'rsc:export
        {--out= : Directory to write the static site into, defaulting to rsc.export_path}
        {--force : Export anyway, leaving routes that need a server broken}';

    protected $description = 'Write the prerendered site out for a static host';

    public function handle(): int
    {
        $source = config('rsc.static_path', storage_path('framework/rsc-static'));

        if (! is_dir($source)) {
            $this->error('Nothing prerendered yet. Run: php artisan rsc:build');

            return self::FAILURE;
        }

        $out = $this->option('out') ?: (string) config('rsc.export_path', 'dist');
        $out = str_starts_with($out, '/') ? $out : base_path($out);

        $needsServer = $this->routesNeedingServer($source);

        if ($needsServer !== [] && ! $this->option('force')) {
            $this->error('These routes are rendered per request and cannot be exported:');
            $this->newLine();

            foreach ($needsServer as $route) {
                $this->line("  {$route}");
            }

            $this->newLine();
            $this->line('A partially prerendered route ships a shell it never fills in, so exporting');
            $this->line('one produces a page that loads and then stays empty. Make them static, or');
            $this->line('pass --force to export the rest and leave these broken.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($out);

        $pages = $this->writePages($source, $out);
        $assets = $this->copyAssets($out);

        $this->newLine();
        $this->info("Exported {$pages} page(s) to {$out}");

        if ($assets !== null) {
            $this->line("Assets copied from {$assets}");
        }

        if ($needsServer !== []) {
            $this->warn(count($needsServer).' route(s) exported without their dynamic half.');
        }

        return self::SUCCESS;
    }

    /**
     * Routes the build could not render completely.
     *
     * A `.ppr.html` is a shell whose remainder is fetched at request time, so
     * its presence means this route has no complete HTML to export.
     *
     * @return list<string>
     */
    private function routesNeedingServer(string $source): array
    {
        $routes = [];

        foreach (File::allFiles($source) as $file) {
            if (! str_ends_with($file->getFilename(), '.ppr.html')) {
                continue;
            }

            $routes[] = '/'.trim(
                str_replace('.ppr.html', '', $file->getRelativePathname()),
                '/'
            );
        }

        sort($routes);

        return $routes;
    }

    /** Lay each route out as a directory with an index, plus its payloads. */
    private function writePages(string $source, string $out): int
    {
        $written = 0;

        foreach (File::allFiles($source) as $file) {
            $name = $file->getFilename();

            if (! str_ends_with($name, '.html') || str_ends_with($name, '.ppr.html')) {
                continue;
            }

            $route = substr($file->getRelativePathname(), 0, -strlen('.html'));
            // The root is the out dir itself; everything else is a directory
            // with an index, so urls stay extensionless.
            $dir = $route === 'index' ? $out : $out.'/'.$route;

            File::ensureDirectoryExists($dir);
            File::copy($file->getPathname(), $dir.'/index.html');

            $base = $file->getPath().'/'.substr($name, 0, -strlen('.html'));

            if (is_file($base.'.flight')) {
                File::copy($base.'.flight', $dir.'/index.rsc');
            }

            $written++;
        }

        return $written;
    }

    /** The browser bundle, which the exported HTML references by url. */
    private function copyAssets(string $out): ?string
    {
        $assets = config('rsc.assets_dir');
        $url = trim((string) config('rsc.assets_url', '/'), '/');

        if (! is_string($assets) || ! is_dir($assets) || $url === '') {
            return null;
        }

        $target = $out.'/'.$url;

        File::ensureDirectoryExists(dirname($target));
        File::copyDirectory($assets, $target);

        return $assets;
    }
}
