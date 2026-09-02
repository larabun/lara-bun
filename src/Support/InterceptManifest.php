<?php

namespace LaravelRsc\Support;

use LaravelRsc\PageScanner;

/**
 * The intercepted URL patterns the client router needs.
 *
 * The browser decides whether a click is an interception before it asks the
 * server, so it needs these up front. PageScanner stays the only thing that
 * resolves the (.)/(..)/(...) convention; this shapes its result for the client.
 */
class InterceptManifest
{
    /**
     * @return list<array{urlPattern: string, slot: string}>
     */
    public static function discover(string $appDir): array
    {
        if (! is_dir($appDir)) {
            return [];
        }

        $scanner = new PageScanner($appDir);
        $scanner->scan();

        $entries = [];

        foreach ($scanner->getPages() as $page) {
            foreach ($page->interceptRoutes as $intercept) {
                $entries[] = [
                    'urlPattern' => self::clientUrlPattern($page->componentName),
                    'slot' => $intercept['slot'],
                ];
            }
        }

        return $entries;
    }

    /**
     * The client router's URL pattern for an intercepted page.
     *
     * Takes the component path of the page being intercepted — the target, not
     * the interceptor. Interceptor paths carry a (.)/(..)/(...) marker whose
     * level this deliberately does not resolve; PageScanner already did that to
     * decide which page the interceptor belongs to.
     *
     * Derived from the component path rather than the Laravel route pattern,
     * which compiles both `[id]` and `[...slug]` down to `{param}` — matching a
     * catch-all intercept as a single segment would silently send the wrong
     * routes to the modal.
     */
    public static function clientUrlPattern(string $componentName): string
    {
        $path = preg_replace('#^app/#', '', $componentName);
        $path = preg_replace('#/?page$#', '', (string) $path);

        $segments = array_filter(
            explode('/', (string) $path),
            // Route groups and parallel-route slots contribute no URL segment.
            fn (string $segment) => $segment !== ''
                && ! str_starts_with($segment, '@')
                && ! preg_match('/^\(.*\)$/', $segment),
        );

        return $segments === [] ? '/' : '/'.implode('/', $segments);
    }
}
