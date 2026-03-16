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
     * Serve a PPR response: cached shell with streamed Suspense completions.
     *
     * 1. Cached shell (with Suspense fallbacks) sent immediately → fast TTFB
     * 2. Fresh rscHtmlStream render started with real params
     * 3. Shell portion of fresh render is discarded (already sent from cache)
     * 4. Suspense completion chunks streamed to client (swap fallbacks with real content)
     * 5. Flight payload + scripts sent for hydration
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

            $clientChunks = $meta['clientChunks'] ?? [];
            $rscPayload = '';

            try {
                $component = $meta['component'] ?? '';
                $layouts = $meta['layouts'] ?? [];
                $route = $request->route();
                $props = $route ? $route->parameters() : [];

                $layoutEntries = [];

                foreach ($layouts as $layout) {
                    $layoutEntries[] = is_array($layout) ? $layout : ['component' => $layout, 'props' => []];
                }

                // Start fresh HTML stream render with real data
                $bridge = app(BunBridge::class);
                $generator = $bridge->rscHtmlStream($component, $props, $layoutEntries);

                // Skip first yield (metadata)
                $generator->current();
                $generator->next();

                $shellPhase = true;

                while ($generator->valid()) {
                    $value = $generator->current();

                    if (is_array($value) && isset($value['rscPayload'])) {
                        $rscPayload = $value['rscPayload'];
                        $generator->next();

                        continue;
                    }

                    if (is_string($value)) {
                        // Detect when we've moved past the shell into completions.
                        // React's completions contain hidden template divs and $RC scripts.
                        if ($shellPhase) {
                            if (str_contains($value, 'hidden id="S:') || str_contains($value, '$RC(') || str_contains($value, '$RS(')) {
                                $shellPhase = false;
                            }
                        }

                        // Only send completion chunks, not the shell (already sent from cache)
                        if (! $shellPhase) {
                            echo $value;
                            flush();
                        }
                    }

                    $generator->next();
                }
            } catch (\Throwable) {
                // If streaming fails, the page still works with fallback content
            }

            // Send scripts with Flight payload for hydration
            echo BunServiceProvider::renderRscScripts($rscPayload, $clientChunks);

            // Send closing tags
            echo substr($shell, $markerPos + strlen($marker));
            flush();
        }, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
