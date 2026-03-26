<?php

namespace LaraBun\Rsc;

use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use LaraBun\BunBridge;
use LaraBun\BunServiceProvider;
use Symfony\Component\Process\Process;

class PrerenderService
{
    /**
     * Discover ALL RSC routes (any route with _rsc_component default).
     *
     * @return Collection<int, Route>
     */
    public function discoverRscRoutes(): Collection
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => isset($route->defaults['_rsc_component']))
            ->filter(fn (Route $route) => in_array('GET', $route->methods()));
    }

    /**
     * Resolve the concrete URLs for a given route.
     *
     * Non-parameterized routes return a single URL.
     * Parameterized routes with staticPaths() return expanded URLs.
     * Parameterized routes without staticPaths() return an empty array.
     *
     * @return list<string>
     */
    public function resolveUrls(Route $route): array
    {
        $uri = $route->uri();

        if (! str_contains($uri, '{')) {
            return ['/'.ltrim($uri, '/')];
        }

        $staticPaths = $route->defaults['_static_paths'] ?? null;

        if ($staticPaths === null) {
            return [];
        }

        $paramNames = $route->parameterNames();

        return collect($staticPaths)->map(function ($params) use ($uri, $paramNames) {
            if (is_string($params)) {
                $params = [$paramNames[0] => $params];
            }

            $url = $uri;

            foreach ($params as $key => $value) {
                $url = str_replace(["{{$key}}", "{{$key}?}"], $value, $url);
            }

            return '/'.ltrim($url, '/');
        })->all();
    }

    /**
     * Pre-render a single URL and write static files.
     *
     * @return array{type: string, reason: string|null}
     */
    public function prerenderUrl(string $url, Route $route, string $outputPath): array
    {
        $rscResponse = $this->resolveRscResponse($route, $url);

        if (! $rscResponse instanceof RscResponse) {
            return ['type' => 'skipped', 'reason' => 'not an RscResponse'];
        }

        // Step 1: Classify using PPR shell render (fast, never hangs).
        // Mock php() returns never-resolving Promises — if the page uses
        // php(), it's detected as dynamic. If not, it's static.
        $shell = app(BunBridge::class)->rscPprShell(
            $rscResponse->getComponent(),
            $rscResponse->getProps(),
            $rscResponse->getLayouts(),
        );

        if ($shell['timedOut']) {
            return ['type' => 'dynamic', 'reason' => 'awaits async data'];
        }

        // If page uses php() or other dynamic APIs, it's PPR — skip full render
        if ($shell['usedDynamicApis'] ?? false) {
            return ['type' => 'dynamic', 'reason' => 'usedDynamicApis'];
        }

        // Static page — full render to get HTML + Flight payload
        $result = app(BunBridge::class)->rscWithoutCallbacks(
            $rscResponse->getComponent(),
            $rscResponse->getProps(),
            $rscResponse->getLayouts(),
        );

        $version = $rscResponse->getVersion();

        // Apply page metadata (title, og:image, icons, etc.) from the RSC bundle
        // so buildMetaTags() can inject them into the pre-rendered HTML.
        if (isset($result['metadata']) && is_array($result['metadata'])) {
            $rscResponse->applyMetadataDefaults($result['metadata']);
        }

        $html = $this->buildHtmlPage($url, $rscResponse->getComponent(), $version, $result, $rscResponse);

        $path = trim($url, '/') ?: 'index';
        File::ensureDirectoryExists(dirname("{$outputPath}/{$path}.html"));

        File::put("{$outputPath}/{$path}.html", $html);
        File::put("{$outputPath}/{$path}.flight", $result['rscPayload']);
        $meta = [
            'clientChunks' => $result['clientChunks'],
            'version' => $version,
        ];

        $viewData = $rscResponse->getViewData();

        if (isset($viewData['title'])) {
            $meta['title'] = $viewData['title'];
        }

        File::put("{$outputPath}/{$path}.meta.json", json_encode($meta, JSON_THROW_ON_ERROR));

        return ['type' => 'static', 'reason' => null];
    }

    public function resolveRscResponse(Route $route, string $url): mixed
    {
        $params = $this->extractParams($route, $url);

        $request = app('request');
        $request->setRouteResolver(fn () => $route);
        $route->bind($request);

        foreach ($params as $key => $value) {
            $route->setParameter($key, $value);
        }

        $action = $route->getAction('uses');

        return app()->call($action, $params);
    }


    public const PPR_PAYLOAD_MARKER = '<!--__RSC_PPR_PAYLOAD__-->';

    public const NONCE_PLACEHOLDER = '__RSC_CSP_NONCE__';

    /**
     * Pre-render a PPR shell for a parameterized route.
     *
     * Uses handleRscPprShell which mocks php() so async components suspend
     * and Suspense shows fallback content. The shell (layout + fallbacks)
     * is captured and cached for all param values.
     *
     * @return array{type: string, reason: string|null}
     */
    public function prerenderPprShell(string $uri, Route $route, string $outputPath): array
    {
        // Use placeholder params to resolve the route controller
        $paramNames = $route->parameterNames();
        $dummyParams = [];

        foreach ($paramNames as $name) {
            $dummyParams[$name] = '__ppr__';
        }

        $dummyUrl = ltrim($uri, '/');

        foreach ($dummyParams as $key => $value) {
            $dummyUrl = str_replace(["{{$key}}", "{{$key}?}"], $value, $dummyUrl);
        }

        $dummyUrl = '/'.ltrim($dummyUrl, '/');

        $rscResponse = $this->resolveRscResponse($route, $dummyUrl);

        if (! $rscResponse instanceof RscResponse) {
            return ['type' => 'skipped', 'reason' => 'not an RscResponse'];
        }

        $result = app(BunBridge::class)->rscPprShell(
            $rscResponse->getComponent(),
            $rscResponse->getProps(),
            $rscResponse->getLayouts(),
        );

        $shellBody = $result['timedOut']
            ? '<div style="padding:40px"><div style="height:32px;width:280px;background:rgba(255,255,255,0.06);border-radius:8px;margin-bottom:16px;animation:pulse 1.5s ease-in-out infinite"></div><div style="height:200px;background:rgba(255,255,255,0.03);border-radius:12px;border:1px solid rgba(255,255,255,0.06);animation:pulse 1.5s ease-in-out infinite"></div><style>@keyframes pulse{0%,100%{opacity:.4}50%{opacity:.8}}</style></div>'
            : $result['shellHtml'];

        $version = $rscResponse->getVersion();

        if (BunServiceProvider::cspNonce() !== null) {
            app()->instance('csp-nonce', self::NONCE_PLACEHOLDER);
        }

        $initialJson = json_encode([
            'url' => self::PPR_PAYLOAD_MARKER,
            'component' => $rscResponse->getComponent(),
            'version' => $version,
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG);

        $rootView = config('bun.rsc.root_view', 'lara-bun::rsc-app');

        $html = view($rootView, [
            ...$rscResponse->getViewData(),
            'body' => $shellBody,
            'initialJson' => $initialJson,
            'scripts' => self::PPR_PAYLOAD_MARKER,
            'cssLinks' => $rscResponse->resolveCssLinks(),
        ])->render();

        // Store under the URI pattern (e.g. posts/_id_)
        $path = trim(str_replace(['{', '}'], ['_', '_'], ltrim($uri, '/')), '/') ?: 'index';
        File::ensureDirectoryExists(dirname("{$outputPath}/{$path}.ppr.html"));

        File::put("{$outputPath}/{$path}.ppr.html", $html);

        $meta = [
            'clientChunks' => $result['clientChunks'],
            'version' => $version,
            'component' => $rscResponse->getComponent(),
            'layouts' => $rscResponse->getLayouts(),
            'parameterized' => true,
            'uriPattern' => $uri,
        ];

        File::put("{$outputPath}/{$path}.ppr-meta.json", json_encode($meta, JSON_THROW_ON_ERROR));

        return ['type' => 'ppr', 'reason' => null];
    }

    /**
     * Pre-render a PPR page — shell with Suspense fallbacks and a placeholder
     * for the Flight payload.
     *
     * Uses handleRscPprShell which mocks php() so async components suspend.
     * At request time, the shell is served instantly and a fresh RSC render
     * streams Suspense completions + Flight payload.
     *
     * @return array{type: string, reason: string|null}
     */
    public function prerenderPpr(string $url, Route $route, string $outputPath): array
    {
        $rscResponse = $this->resolveRscResponse($route, $url);

        if (! $rscResponse instanceof RscResponse) {
            return ['type' => 'skipped', 'reason' => 'not an RscResponse'];
        }

        $result = app(BunBridge::class)->rscPprShell(
            $rscResponse->getComponent(),
            $rscResponse->getProps(),
            $rscResponse->getLayouts(),
        );

        $shellBody = $result['timedOut']
            ? '<div style="padding:40px"><div style="height:32px;width:280px;background:rgba(255,255,255,0.06);border-radius:8px;margin-bottom:16px;animation:pulse 1.5s ease-in-out infinite"></div><div style="height:200px;background:rgba(255,255,255,0.03);border-radius:12px;border:1px solid rgba(255,255,255,0.06);animation:pulse 1.5s ease-in-out infinite"></div><style>@keyframes pulse{0%,100%{opacity:.4}50%{opacity:.8}}</style></div>'
            : $result['shellHtml'];

        $version = $rscResponse->getVersion();

        $initialJson = json_encode([
            'url' => $url,
            'component' => $rscResponse->getComponent(),
            'version' => $version,
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG);

        $rootView = config('bun.rsc.root_view', 'lara-bun::rsc-app');

        $html = view($rootView, [
            ...$rscResponse->getViewData(),
            'body' => $shellBody,
            'initialJson' => $initialJson,
            'scripts' => self::PPR_PAYLOAD_MARKER,
            'cssLinks' => $rscResponse->resolveCssLinks(),
        ])->render();

        $path = trim($url, '/') ?: 'index';
        File::ensureDirectoryExists(dirname("{$outputPath}/{$path}.html"));

        File::put("{$outputPath}/{$path}.ppr.html", $html);

        $meta = [
            'clientChunks' => $result['clientChunks'],
            'version' => $version,
            'component' => $rscResponse->getComponent(),
            'layouts' => $rscResponse->getLayouts(),
        ];

        $viewData = $rscResponse->getViewData();

        if (isset($viewData['title'])) {
            $meta['title'] = $viewData['title'];
        }

        File::put("{$outputPath}/{$path}.ppr-meta.json", json_encode($meta, JSON_THROW_ON_ERROR));

        return ['type' => 'ppr', 'reason' => null];
    }


    /**
     * @param  array{body: string, rscPayload: string, clientChunks: string[]}  $result
     */
    public function buildHtmlPage(string $url, string $component, string $version, array $result, RscResponse $rscResponse): string
    {
        // Use a placeholder nonce during prerender so the cached HTML can be
        // patched with the real per-request nonce at serve time.
        if (BunServiceProvider::cspNonce() !== null) {
            app()->instance('csp-nonce', self::NONCE_PLACEHOLDER);
        }

        $initialJson = json_encode([
            'url' => $url,
            'component' => $component,
            'version' => $version,
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG);

        $scripts = BunServiceProvider::renderRscScripts($result['rscPayload'], $result['clientChunks']);
        $rootView = config('bun.rsc.root_view', 'lara-bun::rsc-app');

        $html = view($rootView, [
            ...$rscResponse->getViewData(),
            'body' => $result['body'],
            'initialJson' => $initialJson,
            'scripts' => $scripts,
            'cssLinks' => $rscResponse->resolveCssLinks(),
        ])->render();

        // Inject metadata tags (title, og:*, icons) into <head>
        $metaTags = $rscResponse->buildMetaTags();

        if ($metaTags !== '' && stripos($html, '</head>') !== false) {
            $html = str_ireplace('</head>', $metaTags."\n</head>", $html);
        }

        return $html;
    }

    /**
     * Start or connect to the Bun worker process.
     *
     * @return Process|null|false Process if started, null if already running, false on failure
     */
    public function ensureBunWorker(bool $forceRestart = false): Process|null|false
    {
        $socketPath = config('bun.socket_path', '/tmp/bun-bridge.sock');

        if (file_exists($socketPath)) {
            if ($forceRestart) {
                // Kill existing worker so we start fresh with new bundles
                try {
                    app(BunBridge::class)->disconnect();
                } catch (\Throwable) {
                }

                @unlink($socketPath);
            } else {
                try {
                    app(BunBridge::class)->ping();

                    return null;
                } catch (\Throwable) {
                    @unlink($socketPath);
                }
            }
        }

        $process = new Process([PHP_BINARY, base_path('artisan'), 'bun:serve']);
        $process->setTimeout(null);
        $process->start(function ($type, $buffer): void {
            // Forward worker output to stderr so the user can see startup errors
            fwrite(STDERR, $buffer);
        });

        $maxWait = 15;
        $waited = 0;

        while ($waited < $maxWait) {
            usleep(500_000);
            $waited += 0.5;

            if (file_exists($socketPath)) {
                try {
                    // Reset the singleton so it connects to the fresh socket
                    app()->forgetInstance(BunBridge::class);
                    app(BunBridge::class)->ping();

                    return $process;
                } catch (\Throwable) {
                    // Socket exists but not ready yet
                }
            }

            if (! $process->isRunning()) {
                return false;
            }
        }

        $process->stop(5);

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function extractParams(Route $route, string $url): array
    {
        $paramNames = $route->parameterNames();

        if (empty($paramNames)) {
            return [];
        }

        $uri = $route->uri();
        $pattern = preg_replace('/\{(\w+)\??\}/', '(?P<$1>[^/]+)', $uri);
        $urlPath = ltrim($url, '/');
        $params = [];

        if (preg_match('#^'.$pattern.'$#', $urlPath, $matches)) {
            foreach ($paramNames as $name) {
                if (isset($matches[$name])) {
                    $params[$name] = $matches[$name];
                }
            }
        }

        return $params;
    }
}
