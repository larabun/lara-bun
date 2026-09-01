<?php

namespace LaravelRsc\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LaravelRsc\LaravelRscServiceProvider;
use LaravelRsc\Rsc\Header;
use LaravelRsc\Rsc\PrerenderService;
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

                // Like the live SPA response, the prerendered Flight payload is
                // self-describing — client references, stylesheet <link>s and
                // <title>/<meta> all travel inside it.
                return new Response(file_get_contents($flightFile), 200, [
                    'Content-Type' => 'text/x-component',
                    Header::X_RSC_VERSION => $meta['version'] ?? '',
                    'X-Accel-Buffering' => 'no',
                ]);
            }
        } else {
            $htmlFile = "{$basePath}/{$path}.html";

            if (file_exists($htmlFile)) {
                return $this->serveHtml($request, file_get_contents($htmlFile), false);
            }

            // PPR: the shell is the part of the page that does not depend on
            // request data. Serving it immediately gives the browser a painted
            // page and the client bootstrap; the Suspense holes fill from the
            // Flight request the bootstrap makes, which is never cached.
            $shellFile = "{$basePath}/{$path}.ppr.html";

            if (file_exists($shellFile)) {
                return $this->serveHtml($request, file_get_contents($shellFile), true);
            }
        }

        // Pages with no prerendered artifact fall through to the normal
        // rendering path, which handles Suspense streaming natively.
        return $next($request);
    }

    /**
     * Serve prerendered HTML, with an ETag so a CDN or browser can revalidate
     * cheaply. A PPR shell is request-independent by construction, so it may be
     * cached publicly; a fully prerendered page is served as-is.
     */
    protected function serveHtml(Request $request, string $html, bool $isShell): Response
    {
        // Replace the build-time nonce placeholder with the real per-request
        // CSP nonce so inline scripts pass CSP checks.
        $nonce = LaravelRscServiceProvider::cspNonce();

        if ($nonce) {
            $html = str_replace(PrerenderService::NONCE_PLACEHOLDER, $nonce, $html);
        }

        $headers = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'ETag' => '"'.md5($html).'"',
        ];

        if ($isShell) {
            $headers['Cache-Control'] = $this->shellCacheControl($nonce !== null);

            // Tag the shell so a deploy hook can purge every shell at once
            // rather than waiting out the TTL. Cloudflare reads Cache-Tag,
            // Fastly and Varnish read Surrogate-Key.
            $tags = 'larabun-shell';
            $headers['Cache-Tag'] = $tags;
            $headers['Surrogate-Key'] = $tags;
        }

        $response = new Response($html, 200, $headers);

        if ($request->headers->get('If-None-Match') === $headers['ETag']) {
            $response->setNotModified();
        }

        return $response;
    }

    /**
     * A per-request CSP nonce is baked into the body, so a shared cache would
     * hand every visitor the same nonce and defeat the policy. Those responses
     * stay private; otherwise the shell is CDN-cacheable.
     */
    protected function shellCacheControl(bool $hasNonce): string
    {
        if ($hasNonce) {
            return 'private, no-store';
        }

        $ttl = (int) config('bun.rsc.shell_ttl', 3600);
        $swr = (int) config('bun.rsc.shell_stale_while_revalidate', 86400);

        return "public, max-age=0, s-maxage={$ttl}, stale-while-revalidate={$swr}";
    }
}
