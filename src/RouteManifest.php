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
    ) {}

    /** The manifest the build writes. */
    public static function forApp(): self
    {
        return new self(base_path('bootstrap/rsc/vite/routes.json'));
    }

    /** Whether a build has happened, so there is anything to register. */
    public static function exists(): bool
    {
        return is_file(base_path('bootstrap/rsc/vite/routes.json'));
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

            $this->pages[] = new PageDefinition(
                componentName: $route['component'],
                urlPattern: $url,
                layouts: $route['layouts'] ?? [],
                loadings: $route['loadings'] ?? [],
                parallelSlots: $route['slots'] ?? [],
                isDynamic: (bool) preg_match('/\{[^}]+\}/', $url),
                routeConfigPath: self::absolute($route['config'] ?? null),
                directoryConfigPaths: array_map(
                    fn (string $path) => (string) self::absolute($path),
                    $route['ancestorConfigs'] ?? [],
                ),
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

    /**
     * A manifest path, as this host has to use it.
     *
     * The build writes them relative to the project root, because an absolute
     * path is only true on the machine that produced it and building in a
     * container is ordinary.
     */
    private static function absolute(?string $path): ?string
    {
        return $path === null ? null : base_path($path);
    }
}
