<?php

namespace RscKit;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RscActionController
{
    /**
     * Resolve a url to what renders it.
     *
     * Costs one route match and one run of the page's props. Only done when
     * the browser says where it is — and returning null simply means an action
     * from that page cannot revalidate anything, not that it fails.
     *
     * @return array{component: string, props: array<string, mixed>, layouts: array<int, mixed>, loadings: array<int, string>, parallelSlots: array<string, string>}|null
     */
    private function pageContext(?string $url): ?array
    {
        if ($url === null || $url === '') {
            return null;
        }

        $current = app('request');

        try {
            $probe = Request::create($url, 'GET');
            $route = app('router')->getRoutes()->match($probe);
            $probe->setRouteResolver(fn () => $route);

            // The page's own props may read the request; it must be the page's
            // request, not the POST that is running the action.
            app()->instance('request', $probe);

            $response = app()->call($route->getAction('uses'), $route->parameters());

            if (! $response instanceof RscResponse) {
                return null;
            }

            return [
                'component' => $response->getComponent(),
                'props' => $response->getProps(),
                'layouts' => $response->getLayouts(),
                'loadings' => $response->getLoadings(),
                'parallelSlots' => $response->getParallelSlots(),
            ];
        } catch (\Throwable) {
            // A url that no longer routes, or a page that cannot be built
            // without more than this. Revalidation is an optimisation; the
            // action itself must still run.
            return null;
        } finally {
            app()->instance('request', $current);
        }
    }

    public function __invoke(Request $request): StreamedResponse|JsonResponse|Response
    {
        $actionId = $request->header(Header::X_RSC_ACTION);

        if (! $actionId) {
            abort(400, 'Missing X-RSC-Action header');
        }

        $body = $request->getContent();
        $contentType = $request->header(Header::X_RSC_CONTENT_TYPE, 'text/plain');
        $bridge = app(RuntimeBridge::class);

        // Which page the action was invoked from. The browser knows the url;
        // only the host knows which components render it, and the worker needs
        // those to re-render anything the action says it invalidated.
        $page = $this->pageContext($request->header(Header::X_RSC_REFERER));

        $generator = $bridge->rscAction($actionId, $body, $contentType, $page);

        try {
            $first = $generator->current();
        } catch (AuthenticationException) {
            return response('', 401)
                ->header('X-RSC-Redirect', route('login'));
        } catch (RscRedirectException $e) {
            return ResponseHeaders::applyTo(
                response('', $e->getStatus())
                    ->header('X-RSC-Redirect', $e->getLocation()),
                $e->getHeaders(),
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        // Read now: the action set them before its first chunk, and this is
        // the last moment before the response exists.
        $setByAction = $bridge->actionHeaders();

        return ResponseHeaders::applyTo(new StreamedResponse(function () use ($generator, $first): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            if ($first !== null) {
                echo $first;
                flush();
            }

            $generator->next();

            while ($generator->valid()) {
                echo $generator->current();
                flush();
                $generator->next();
            }
        }, 200, [
            'Content-Type' => 'text/x-component',
            'X-Accel-Buffering' => 'no',
        ]), $setByAction);
    }
}
