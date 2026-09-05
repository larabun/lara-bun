<?php

namespace RscKit\Console;

use Illuminate\Console\Command;
use RscKit\PageDefinition;
use RscKit\PageRoute;
use RscKit\RouteManifest;

class RscPagesCommand extends Command
{
    protected $signature = 'rsc:pages';

    protected $description = 'List all file-based RSC page routes';

    public function handle(): int
    {
        if (! RouteManifest::exists()) {
            $this->warn('No route manifest found. Run: php artisan rsc:build');

            return self::SUCCESS;
        }

        $pages = RouteManifest::forApp()->pages();

        if ($pages === []) {
            $this->warn('No page.tsx files found.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($pages as $page) {
            $middleware = $this->resolveMiddleware($page);

            $rows[] = [
                $page->urlPattern === '/' ? '/' : '/'.$page->urlPattern,
                $page->componentName,
                implode(' → ', $page->layouts) ?: '—',
                $page->isDynamic ? 'dynamic' : 'static',
                $middleware ?: '—',
                $page->domain ?? '—',
            ];
        }

        $this->table(
            ['URL', 'Component', 'Layouts', 'Type', 'Middleware', 'Domain'],
            $rows,
        );

        return self::SUCCESS;
    }

    private function resolveMiddleware(PageDefinition $page): string
    {
        $middleware = ['web'];

        foreach ($page->directoryConfigPaths as $configPath) {
            $config = require $configPath;

            if ($config instanceof PageRoute) {
                $middleware = array_merge($middleware, $config->getMiddleware());
            }
        }

        if ($page->routeConfigPath !== null) {
            $config = require $page->routeConfigPath;

            if ($config instanceof PageRoute) {
                $middleware = array_merge($middleware, $config->getMiddleware());
            }
        }

        if (! $page->isDynamic) {
            $middleware[] = 'static';
        }

        return implode(', ', array_unique($middleware));
    }
}
