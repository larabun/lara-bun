<?php

namespace LaraBun\Rsc;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class PageController
{
    public function handle(Request $request): RscResponse
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

        return $this->buildResponse($route);
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

            if ($config instanceof PageRoute && $config->getViewData()) {
                $viewData = app()->call($config->getViewData(), $props);

                foreach ($viewData as $key => $value) {
                    $response->withViewData($key, $value);
                }
            }
        }
    }
}
