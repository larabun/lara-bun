<?php

namespace LaravelRsc;

use RuntimeException;

/**
 * The route tree, read from what the build wrote.
 *
 * The plugin walks app/ to generate its entries and now writes down what it
 * found. This turns that into the same PageDefinitions the scanner produced by
 * walking the tree a second time.
 *
 * Urls arrive as segments rather than as a pattern, because the pattern is this
 * host's dialect — Laravel writes {slug} where another writes :slug — so that
 * translation happens here rather than in the build.
 *
 * What stays here is what is Laravel's: route.php lives beside a page by this
 * host's convention, and the build has no reason to know about it.
 */
class RouteManifest
{
    /** @var list<PageDefinition> */
    private array $pages = [];

    /** @var array{output: string, exportPath: string, payloadName: string}|null */
    private ?array $build = null;

    public function __construct(
        private string $manifestPath,
        private string $appDir,
    ) {}

    /** The manifest the build writes, and the app dir it describes. */
    public static function forApp(): self
    {
        return new self(
            base_path('bootstrap/rsc/vite/routes.json'),
            rtrim((string) config('rsc.source_dir'), '/').'/app',
        );
    }

    /**
     * @return list<PageDefinition>
     */
    public function pages(): array
    {
        if ($this->pages === []) {
            $this->read();
        }

        return $this->pages;
    }

    /**
     * What the build decided, for the steps that run after it.
     *
     * output and where an export goes are declared in the vite config, because
     * they are decisions about what the build produces. This host acts on them
     * afterwards rather than passing them in.
     *
     * @return array{output: string, exportPath: string, payloadName: string}
     */
    public function build(): array
    {
        if ($this->build === null) {
            $this->read();
        }

        return $this->build ?? ['output' => 'server', 'exportPath' => 'dist', 'payloadName' => ''];
    }

    private function read(): void
    {
        if (! is_file($this->manifestPath)) {
            throw new RuntimeException(
                "No route manifest at {$this->manifestPath}. Run: php artisan rsc:build"
            );
        }

        $manifest = json_decode((string) file_get_contents($this->manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $intercepts = $this->intercepts($manifest['intercepts'] ?? []);

        $this->build = [
            'output' => $manifest['build']['output'] ?? 'server',
            'exportPath' => $manifest['build']['exportPath'] ?? 'dist',
            'payloadName' => $manifest['build']['payloadName'] ?? '',
        ];

        foreach ($manifest['routes'] ?? [] as $route) {
            $url = self::url($route['segments'] ?? []);
            $dir = $this->pageDir($route['component']);

            $this->pages[] = new PageDefinition(
                componentName: $route['component'],
                urlPattern: $url,
                layouts: $route['layouts'] ?? [],
                loadings: $route['loadings'] ?? [],
                parallelSlots: $route['slots'] ?? [],
                isDynamic: (bool) preg_match('/\{[^}]+\}/', $url),
                routeConfigPath: is_file($dir.'/route.php') ? $dir.'/route.php' : null,
                directoryConfigPaths: $this->ancestorConfigs($dir),
                interceptRoutes: array_values(array_filter(
                    $intercepts,
                    fn (array $i) => $i['interceptedUrl'] === $url,
                )),
            );
        }

        usort($this->pages, fn (PageDefinition $a, PageDefinition $b) => strcmp($a->urlPattern, $b->urlPattern));
    }

    /**
     * Segments in Laravel's dialect.
     *
     * @param  list<array{type: string, value: string}>  $segments
     */
    public static function url(array $segments): string
    {
        if ($segments === []) {
            return '/';
        }

        return implode('/', array_map(
            fn (array $s) => $s['type'] === 'static' ? $s['value'] : '{'.$s['value'].'}',
            $segments,
        ));
    }

    /**
     * @param  list<array{component: string, slot: string, segments: list<array{type: string, value: string}>, marker: string}>  $entries
     * @return list<array{slot: string, component: string, interceptedUrl: string}>
     */
    private function intercepts(array $entries): array
    {
        $intercepts = [];

        foreach ($entries as $entry) {
            $marker = $entry['marker'] ?? '(.)';

            if ($marker !== '(.)') {
                // (..) and (...) name a url relative to somewhere other than
                // here. Nothing has ever used them, and guessing would place
                // the interceptor on a page it was not meant for.
                throw new RuntimeException(
                    "Interception marker {$marker} is not supported yet ({$entry['component']})."
                );
            }

            $intercepts[] = [
                'slot' => $entry['slot'],
                'component' => $entry['component'],
                'interceptedUrl' => self::url($entry['segments']),
            ];
        }

        return $intercepts;
    }

    /** Where a component's files live, so this host can look beside them. */
    private function pageDir(string $component): string
    {
        $relative = preg_replace('#^app/#', '', $component);
        $relative = preg_replace('#/page$#', '', (string) $relative);

        return rtrim($this->appDir.'/'.$relative, '/');
    }

    /**
     * Ancestor route.php files, outermost first, excluding the page's own.
     *
     * @return list<string>
     */
    private function ancestorConfigs(string $dir): array
    {
        $configs = [];
        $current = dirname($dir);
        $root = rtrim($this->appDir, '/');

        while (str_starts_with($current, $root)) {
            $path = $current.'/route.php';

            if (is_file($path)) {
                array_unshift($configs, $path);
            }

            if ($current === $root) {
                break;
            }

            $current = dirname($current);
        }

        return $configs;
    }
}
