<?php

namespace LaraBun\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LaraBun\Rsc\Header;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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

            // PPR pages fall through to the normal rendering path
            // which handles Suspense streaming natively.
        }

        return $next($request);
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
