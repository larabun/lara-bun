<?php

namespace RscKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RscKit\Header;
use RscKit\PrerenderService;
use RscKit\RscKitServiceProvider;
use RscKit\RscResponse;
use RscKit\RuntimeBridge;
use RscKit\Support\DevServer;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServeStaticRsc
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        // Prerendered output is the previous build's; in dev the point is to
        // render from source. Serving the static copy here means the worker is
        // never reached and no edit ever shows up.
        if (DevServer::isActive()) {
            return $next($request);
        }

        $path = trim($request->getPathInfo(), '/') ?: 'index';
        $basePath = config('rsc.static_path', storage_path('framework/rsc-static'));

        if ($request->hasHeader(Header::X_RSC)) {
            $flightFile = "{$basePath}/{$path}.flight";
            $metaFile = "{$basePath}/{$path}.meta.json";

            if (file_exists($flightFile) && file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);
                $chain = $meta['layouts'] ?? [];

                // A client already holding this route's layouts gets the page
                // on its own. Sending the whole document instead would replace
                // the root, and replacing the root unmounts the pages retained
                // behind it — losing the form state going back should restore.
                $held = $request->header(Header::X_RSC_SEGMENTS);
                $shared = $chain === [] ? 0 : RscResponse::commonLayoutDepth($held, $chain);
                $segmentFile = "{$basePath}/{$path}.seg{$shared}.flight";
                $depth = 0;
                $payload = $flightFile;

                // Serve the variant for exactly the depth this client shares.
                // Anything else — no shared layouts, or a build without that
                // variant — is the whole document.
                if ($shared > 0 && file_exists($segmentFile)) {
                    $payload = $segmentFile;
                    $depth = $shared;
                }

                // Like the live SPA response, the prerendered Flight payload is
                // self-describing — client references, stylesheet <link>s and
                // <title>/<meta> all travel inside it.
                return new Response(file_get_contents($payload), 200, [
                    'Content-Type' => 'text/x-component',
                    Header::X_RSC_VERSION => $meta['version'] ?? '',
                    'X-Accel-Buffering' => 'no',
                    Header::X_RSC_SEGMENT_DEPTH => (string) $depth,
                    Header::X_RSC_LAYOUTS => implode(',', $chain),
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
                $stateFile = "{$basePath}/{$path}.postponed.json";
                $shellMetaFile = "{$basePath}/{$path}.ppr-meta.json";

                // Finish the shell here rather than leaving its holes to the
                // browser. The client still hydrates and still fetches its own
                // payload, so this is an addition rather than a replacement: the
                // content is simply in the first response instead of arriving a
                // round trip later.
                if (file_exists($stateFile) && file_exists($shellMetaFile)) {
                    $resumed = $this->resume(
                        $request,
                        file_get_contents($shellFile),
                        json_decode(file_get_contents($shellMetaFile), true) ?: [],
                        json_decode(file_get_contents($stateFile), true)
                    );

                    if ($resumed !== null) {
                        return $resumed;
                    }
                }

                return $this->serveHtml($request, file_get_contents($shellFile), true);
            }
        }

        // Pages with no prerendered artifact fall through to the normal
        // rendering path, which handles Suspense streaming natively.
        return $next($request);
    }

    /**
     * Serve a frozen shell and finish it in the same response.
     *
     * The shell goes out first — that is the byte the browser was waiting for —
     * and the boundaries it left open are rendered now and written behind it.
     * React emits a small script beside each resumed segment that moves it into
     * place as the HTML parses, so the content appears without waiting for the
     * app bundle or for hydration.
     *
     * The call is REPLAYED from what the build recorded, never rebuilt from
     * this request. A resume matches React's slots by key, so an argument that
     * differs from the frozen render at all produces a tree that "doesn't
     * match" — and that fails silently: every boundary falls back to client
     * rendering and the page still looks correct.
     *
     * Returns null when the build did not record enough to replay, which sends
     * the caller back to serving the shell alone.
     */
    protected function resume(Request $request, string $shell, array $meta, mixed $postponed): ?SymfonyResponse
    {
        $component = $meta['component'] ?? null;
        $rendered = $meta['renderedWith'] ?? null;

        // A shell from a build before this existed. Serving it alone is right.
        if (! is_string($component) || ! is_array($rendered)) {
            return null;
        }

        $nonce = RscKitServiceProvider::cspNonce();

        if ($nonce) {
            $shell = str_replace(PrerenderService::NONCE_PLACEHOLDER, $nonce, $shell);
        }

        $bridge = app(RuntimeBridge::class);

        $stream = function () use ($bridge, $shell, $component, $meta, $rendered, $postponed, $nonce): void {
            echo $shell;

            if (ob_get_level() > 0) {
                ob_flush();
            }

            flush();

            try {
                foreach ($bridge->rscResume(
                    $component,
                    $rendered['props'] ?? [],
                    $meta['layouts'] ?? [],
                    $rendered['loadings'] ?? [],
                    $rendered['parallelSlots'] ?? [],
                    $postponed,
                    $nonce,
                    $rendered['pageKey'] ?? ''
                ) as $chunk) {
                    // The first yield is the start frame; only the chunk
                    // strings after it are HTML.
                    if (! is_string($chunk)) {
                        continue;
                    }

                    echo $chunk;

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                }
            } catch (\Throwable $e) {
                // The shell is already on the wire, so there is no status line
                // left to change and nothing useful to say in the body. The
                // client fetches its own payload when it hydrates and fills the
                // boundaries itself, which is what happened before any of this
                // existed — a slower hole, never a wrong page.
                report($e);
            }
        };

        return new StreamedResponse($stream, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // The shell is cacheable on its own; this response is not. It
            // carries the holes, which were rendered for whoever asked.
            'Cache-Control' => 'private, no-store',
            'X-Accel-Buffering' => 'no',
        ]);
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
        $nonce = RscKitServiceProvider::cspNonce();

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

        $ttl = (int) config('rsc.shell_ttl', 3600);
        $swr = (int) config('rsc.shell_stale_while_revalidate', 86400);

        return "public, max-age=0, s-maxage={$ttl}, stale-while-revalidate={$swr}";
    }
}
