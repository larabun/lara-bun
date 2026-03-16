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
            $pprFile = "{$basePath}/{$path}.ppr.html";
            $pprMeta = "{$basePath}/{$path}.ppr-meta.json";

            if (file_exists($pprFile) && file_exists($pprMeta)) {
                return $this->servePprResponse($pprFile, $pprMeta);
            }
        }

        return $next($request);
    }

    /**
     * Serve a PPR response: cached HTML shell with a fresh Flight payload.
     *
     * The shell HTML (with rendered body) is sent immediately for fast TTFB.
     * The Flight payload is generated fresh so the client hydrates with
     * up-to-date data. React reconciles any differences between the cached
     * HTML and the fresh Flight tree.
     */
    private function servePprResponse(string $shellPath, string $metaPath): StreamedResponse
    {
        return new StreamedResponse(function () use ($shellPath, $metaPath): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $shell = file_get_contents($shellPath);
            $meta = json_decode(file_get_contents($metaPath), true);
            $marker = PrerenderService::PPR_PAYLOAD_MARKER;

            $markerPos = strpos($shell, $marker);

            if ($markerPos === false) {
                // No marker — serve as-is (shouldn't happen)
                echo $shell;
                flush();

                return;
            }

            // Send everything before the marker (head + body = fast TTFB)
            echo substr($shell, 0, $markerPos);
            flush();

            // Generate fresh Flight payload
            $clientChunks = $meta['clientChunks'] ?? [];

            try {
                $component = $meta['component'] ?? '';
                $layouts = $meta['layouts'] ?? [];

                $bridge = app(BunBridge::class);
                $result = $bridge->rsc($component, [], $layouts);

                $rscPayload = $result['rscPayload'] ?? '';
            } catch (\Throwable) {
                $rscPayload = '';
            }

            // Send the scripts with fresh payload
            echo BunServiceProvider::renderRscScripts($rscPayload, $clientChunks);

            // Send everything after the marker (closing tags)
            echo substr($shell, $markerPos + strlen($marker));
            flush();
        }, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
