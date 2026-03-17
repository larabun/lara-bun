<?php

namespace LaraBun\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LaraBun\BunBridge;
use LaraBun\BunServiceProvider;
use LaraBun\Rsc\Header;
use LaraBun\Rsc\PrerenderService;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServeStaticRsc
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $path = trim($request->getPathInfo(), '/') ?: 'index';
        $basePath = config('bun.rsc.static_path', storage_path('framework/rsc-static'));

        if ($request->hasHeader(Header::X_RSC)) {
            // Fully static Flight response
            $flightFile = "{$basePath}/{$path}.flight";
            $metaFile = "{$basePath}/{$path}.meta.json";

            if (file_exists($flightFile) && file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);

                $headers = [
                    'Content-Type' => 'text/x-component',
                    Header::X_RSC_CHUNKS => json_encode($meta['clientChunks'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    Header::X_RSC_VERSION => $meta['version'] ?? '',
                    'X-Accel-Buffering' => 'no',
                ];

                if (isset($meta['title'])) {
                    $headers[Header::X_RSC_TITLE] = rawurlencode($meta['title']);
                }

                return new Response(file_get_contents($flightFile), 200, $headers);
            }

            // PPR SPA navigation — generate fresh Flight payload via buffered rsc()
            // This avoids the streaming callback socket path which is less reliable.
            $pprMeta = "{$basePath}/{$path}.ppr-meta.json";

            if (! file_exists($pprMeta)) {
                // Check parameterized PPR
                $route = $request->route();

                if ($route && str_contains($route->uri(), '{')) {
                    $patternPath = str_replace(['{', '}'], ['_', '_'], trim($route->uri(), '/'));
                    $pprMeta = "{$basePath}/{$patternPath}.ppr-meta.json";
                }
            }

            if (file_exists($pprMeta)) {
                return $this->servePprFlightResponse($request, $pprMeta);
            }
        } else {
            // Fully static HTML
            $htmlFile = "{$basePath}/{$path}.html";

            if (file_exists($htmlFile)) {
                return new Response(file_get_contents($htmlFile), 200, [
                    'Content-Type' => 'text/html; charset=UTF-8',
                ]);
            }

            // PPR: serve cached shell with fresh Flight payload
            // Check concrete path first (non-parameterized or pre-rendered with staticPaths)
            $pprFile = "{$basePath}/{$path}.ppr.html";
            $pprMeta = "{$basePath}/{$path}.ppr-meta.json";

            if (file_exists($pprFile) && file_exists($pprMeta)) {
                return $this->servePprResponse($request, $pprFile, $pprMeta);
            }

            // Check for parameterized PPR shell (stored under URI pattern)
            $route = $request->route();

            if ($route && str_contains($route->uri(), '{')) {
                $patternPath = str_replace(['{', '}'], ['_', '_'], trim($route->uri(), '/'));
                $pprFile = "{$basePath}/{$patternPath}.ppr.html";
                $pprMeta = "{$basePath}/{$patternPath}.ppr-meta.json";

                if (file_exists($pprFile) && file_exists($pprMeta)) {
                    return $this->servePprResponse($request, $pprFile, $pprMeta);
                }
            }
        }

        return $next($request);
    }

    /**
     * Serve a PPR response: cached shell for fast TTFB, then stream
     * fresh content via rscHtmlStream (the proven Suspense streaming path).
     *
     * 1. Cached shell (with Suspense fallbacks) sent immediately → fast TTFB
     * 2. Fresh rscHtmlStream render streams Suspense completions + Flight payload
     * 3. React swaps fallbacks with real content progressively
     */
    private function servePprResponse(Request $request, string $shellPath, string $metaPath): StreamedResponse
    {
        return new StreamedResponse(function () use ($request, $shellPath, $metaPath): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $shell = file_get_contents($shellPath);
            $meta = json_decode(file_get_contents($metaPath), true);
            $marker = PrerenderService::PPR_PAYLOAD_MARKER;

            $markerPos = strpos($shell, $marker);

            if ($markerPos === false) {
                echo $shell;
                flush();

                return;
            }

            // For parameterized routes, fix the __RSC_INITIAL__ URL placeholder
            $isParameterized = ($meta['parameterized'] ?? false);

            if ($isParameterized) {
                $realUrl = $request->getRequestUri();
                $shell = str_replace(
                    json_encode(PrerenderService::PPR_PAYLOAD_MARKER, JSON_HEX_TAG),
                    json_encode($realUrl, JSON_HEX_TAG),
                    $shell,
                );
                $markerPos = strpos($shell, $marker);

                if ($markerPos === false) {
                    echo $shell;
                    flush();

                    return;
                }
            }

            // Send cached shell (head + body with fallbacks) → fast TTFB
            echo substr($shell, 0, $markerPos);
            flush();

            // Use the streaming path for fresh content — same as normal
            // page loads. Suspense completions arrive progressively.
            try {
                $component = $meta['component'] ?? '';
                $layouts = $meta['layouts'] ?? [];
                $route = $request->route();
                $props = $route ? $route->parameters() : [];

                $layoutEntries = [];

                foreach ($layouts as $layout) {
                    $layoutEntries[] = is_array($layout) ? $layout : ['component' => $layout, 'props' => []];
                }

                $bridge = app(BunBridge::class);
                $generator = $bridge->rscHtmlStream($component, $props, $layoutEntries);

                // Skip first yield (metadata — we already have it from the cached shell)
                $generator->current();
                $generator->next();

                $rscPayload = '';
                $clientChunks = $meta['clientChunks'] ?? [];

                while ($generator->valid()) {
                    $value = $generator->current();

                    if (is_array($value) && isset($value['rscPayload'])) {
                        $rscPayload = $value['rscPayload'];
                        $generator->next();

                        continue;
                    }

                    // The streaming path sends shell HTML + Suspense completions.
                    // We already sent the cached shell, so skip it and only send
                    // the completion chunks (hidden templates + $RC scripts).
                    if (is_string($value)) {
                        echo $value;
                        flush();
                    }

                    $generator->next();
                }

                // Send scripts with Flight payload for hydration
                echo BunServiceProvider::renderRscScripts($rscPayload, $clientChunks);
            } catch (\Throwable $e) {
                report($e);

                // Fallback: send empty scripts so the page at least loads
                echo BunServiceProvider::renderRscScripts('', $meta['clientChunks'] ?? []);
            }

            // Send closing tags
            echo substr($shell, $markerPos + strlen($marker));
            flush();
        }, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Serve a PPR Flight response for SPA navigation.
     *
     * Uses the buffered rsc() call (not the streaming rscStream path)
     * which is more reliable under Octane concurrency.
     */
    private function servePprFlightResponse(Request $request, string $metaPath): StreamedResponse
    {
        $meta = json_decode(file_get_contents($metaPath), true);
        $clientChunks = $meta['clientChunks'] ?? [];
        $version = $meta['version'] ?? '';

        $headers = [
            'Content-Type' => 'text/x-component',
            Header::X_RSC_CHUNKS => json_encode($clientChunks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            Header::X_RSC_VERSION => $version,
            'X-Accel-Buffering' => 'no',
        ];

        return new StreamedResponse(function () use ($request, $meta): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            try {
                $component = $meta['component'] ?? '';
                $layouts = $meta['layouts'] ?? [];
                $route = $request->route();
                $props = $route ? $route->parameters() : [];

                $bridge = app(BunBridge::class);
                $result = $bridge->rsc($component, $props, $layouts);

                echo $result['rscPayload'] ?? '';
                flush();
            } catch (\Throwable $e) {
                // Log but don't crash — client will see a stale page or error
                report($e);
            }
        }, 200, $headers);
    }
}
