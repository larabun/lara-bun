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

                return new Response($html, 200, [
                    'Content-Type' => 'text/html; charset=UTF-8',
                ]);
            }
        }

        // PPR pages and non-cached pages fall through to the normal
        // rendering path which handles Suspense streaming natively.
        return $next($request);
    }
}
