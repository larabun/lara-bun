<?php

namespace LaraBun\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LaraBun\BunServiceProvider;
use LaraBun\Rsc\Header;
use LaraBun\Rsc\PrerenderService;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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

                $clientChunks = $meta['clientChunks'] ?? [];
                $sharedChunks = isset($clientChunks['shared']) ? $clientChunks['shared'] : $clientChunks;

                $headers = [
                    'Content-Type' => 'text/x-component',
                    Header::X_RSC_CHUNKS => json_encode($sharedChunks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    Header::X_RSC_VERSION => $meta['version'] ?? '',
                    'X-Accel-Buffering' => 'no',
                ];

                if (isset($meta['title'])) {
                    $headers[Header::X_RSC_TITLE] = rawurlencode($meta['title']);
                }

                return new Response(file_get_contents($flightFile), 200, $headers);
            }
        } else {
            $htmlFile = "{$basePath}/{$path}.html";

            if (file_exists($htmlFile)) {
                $html = file_get_contents($htmlFile);

                // Replace the build-time nonce placeholder with the real
                // per-request CSP nonce so inline scripts pass CSP checks.
                $nonce = BunServiceProvider::cspNonce();

                if ($nonce) {
                    $html = str_replace(PrerenderService::NONCE_PLACEHOLDER, $nonce, $html);
                }

                $response = new Response($html, 200, [
                    'Content-Type' => 'text/html; charset=UTF-8',
                ]);

                // Add Link preload headers for CSS — enables 103 Early Hints
                // when FrankenPHP/Caddy has early_hints enabled.
                $this->addCssPreloadHeaders($response, $html);

                return $response;
            }
        }

        // PPR pages and non-cached pages fall through to the normal
        // rendering path which handles Suspense streaming natively.
        $response = $next($request);

        // Add Link preload headers for dynamic HTML responses too
        if (str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            $content = $response->getContent();

            if ($content !== false) {
                $this->addCssPreloadHeaders($response, $content);
            }
        }

        return $response;
    }

    /**
     * Extract CSS URLs from <link data-rsc-css> tags and add Link preload headers.
     * FrankenPHP/Caddy can send these as 103 Early Hints when enabled.
     */
    private function addCssPreloadHeaders(SymfonyResponse $response, string $html): void
    {
        if (preg_match_all('/<link[^>]+data-rsc-css[^>]+href="([^"]+)"/', $html, $matches)) {
            $links = [];

            foreach ($matches[1] as $url) {
                $links[] = '<'.e($url).'>; rel=preload; as=style';
            }

            if ($links !== []) {
                $response->headers->set('Link', implode(', ', $links), false);
            }
        }
    }
}
