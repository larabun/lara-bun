<?php

namespace LaravelRsc;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

class PageController
{
    /**
     * A page, or one part of it.
     *
     * The narrow return type is a plain response: a request for one part
     * answers with that part's payload, not with something to be rendered.
     */
    public function handle(Request $request): RscResponse|Response
    {
        $route = $request->route();
        $intercepts = $route->defaults['_rsc_intercepts'] ?? [];

        // Route interception: on SPA navigation with X-RSC-Intercept header,
        // resolve the current page from the referer and render the full tree
        // with the interceptor component placed in the matching parallel slot.
        $interceptSlot = $request->header(Header::X_RSC_INTERCEPT);

        if ($interceptSlot !== null && $request->hasHeader(Header::X_RSC)) {
            $refererUrl = $request->header(Header::X_RSC_REFERER);

            return $this->handleIntercept($request, $intercepts, $interceptSlot, $refererUrl);
        }

        // A client asking for one part of this page back, without mutating
        // anything to earn it. What an action invalidated does not come
        // through here — that travels back inside the action's own answer.
        $revalidate = $request->header(Header::X_RSC_REVALIDATE);

        if ($revalidate !== null && $request->hasHeader(Header::X_RSC)) {
            return $this->revalidate($route, $revalidate);
        }

        return $this->buildResponse($route);
    }

    /** Render one part of this page and answer with it alone. */
    protected function revalidate(\Illuminate\Routing\Route $route, string $target): Response
    {
        $page = $this->buildResponse($route);

        $rendered = app(RuntimeBridge::class)->rscRevalidate($target, [
            'component' => $page->getComponent(),
            'props' => $page->getProps(),
            'layouts' => $page->getLayouts(),
            'loadings' => $page->getLoadings(),
            'parallelSlots' => $page->getParallelSlots(),
        ]);

        return new Response($rendered['rscPayload'], 200, [
            'Content-Type' => 'text/x-component',
            Header::X_RSC_REVALIDATE => $target,
        ]);
    }

    protected function buildResponse(\Illuminate\Routing\Route $route): RscResponse
    {
        $component = $route->defaults['_rsc_component'];
        $layouts = $route->defaults['_rsc_layouts'] ?? [];
        $loadings = $route->defaults['_rsc_loadings'] ?? [];
        $parallelSlots = $route->defaults['_rsc_parallel_slots'] ?? [];
        $configPaths = $route->defaults['_rsc_config_paths'] ?? [];

        $props = $route->parameters();
        $response = new RscResponse($component, $props);
        $response->loadings($loadings);
        $response->parallelSlots($parallelSlots);

        foreach ($layouts as $layout) {
            $response->layout($layout);
        }

        $this->applyViewData($response, $configPaths, $props);

        return $response;
    }

    /**
     * Handle an intercepted route: render the full tree (current page + interceptor in slot).
     *
     * Resolves the current page from the X-RSC-Referer header, then overrides
     * the matching parallel slot with the interceptor component. The client
     * receives a complete tree — React reconciliation keeps the current page
     * DOM intact and only updates the slot.
     *
     * @param  list<array{slot: string, component: string, interceptedUrl: string}>  $intercepts
     */
    protected function handleIntercept(Request $request, array $intercepts, string $slot, ?string $refererUrl): RscResponse
    {
        $intercept = null;

        foreach ($intercepts as $candidate) {
            if ($candidate['slot'] === $slot) {
                $intercept = $candidate;

                break;
            }
        }

        if ($intercept === null) {
            abort(404);
        }

        // Resolve the current page from the referer URL
        if ($refererUrl !== null) {
            try {
                $refererRequest = Request::create($refererUrl, 'GET');
                $refererRoute = Route::getRoutes()->match($refererRequest);

                // Build response from the referer's page (current page stays visible)
                $response = $this->buildResponse($refererRoute);

                // Override the matching slot with the interceptor component.
                // The interceptor receives the target URL's route params.
                $targetParams = $request->route()->parameters();
                $response->overrideSlot($slot, $intercept['component'], $targetParams);

                return $response;
            } catch (\Throwable) {
                // Referer resolution failed — fall through to interceptor-only render
            }
        }

        // Fallback: render just the interceptor component (no referer available)
        $props = $request->route()->parameters();

        return new RscResponse($intercept['component'], $props);
    }

    /**
     * @param  list<string>  $configPaths
     * @param  array<string, mixed>  $props
     */
    protected function applyViewData(RscResponse $response, array $configPaths, array $props): void
    {
        foreach ($configPaths as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $config = require $path;

            if (! $config instanceof PageRoute) {
                continue;
            }

            // viewData → Blade view only (title, meta tags, etc.)
            if ($config->getViewData()) {
                $viewData = app()->call($config->getViewData(), $props);

                foreach ($viewData as $key => $value) {
                    $response->withViewData($key, $value);
                }
            }

            // A route opting out of client JS: innermost config wins, same as
            // everything else resolved here.
            if (! $config->shipsClientJs()) {
                $response->clientJs(false);
            }

            // props → React component props
            if ($config->getProps() !== null) {
                $pageProps = is_callable($config->getProps())
                    ? app()->call($config->getProps(), $props)
                    : $config->getProps();

                foreach ($pageProps as $key => $value) {
                    $response->withProp($key, $value);
                }
            }
        }
    }
}
