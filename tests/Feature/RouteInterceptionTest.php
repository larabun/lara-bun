<?php

use LaravelRsc\Header;
use LaravelRsc\PageDefinition;
use LaravelRsc\PageRouteRegistrar;
use LaravelRsc\RuntimeBridge;

beforeEach(function () {
    $this->bridgeMock = Mockery::mock(RuntimeBridge::class);
    $this->app->instance(RuntimeBridge::class, $this->bridgeMock);
});

test('intercept with referer renders referer page with slot override', function () {
    $this->bridgeMock
        ->shouldReceive('rscStream')
        ->once()
        ->withArgs(function (string $component, array $props, array $layouts, array $loadings, array $parallelSlots, array $slotOverrides) {
            // Should render the FEED page (from referer), not the photo page.
            // The slot override should contain the interceptor with target URL params.
            return $component === 'app/feed/page'
                && isset($slotOverrides['modal'])
                && $slotOverrides['modal']['component'] === 'app/@modal/(.)photo/[id]/page'
                && $slotOverrides['modal']['props']['id'] === '123';
        })
        ->andReturnUsing(function () {
            return (function () {
                yield ['clientChunks' => [], 'metadata' => null];
                yield '0:["$","div",null,{"children":"Feed with modal"}]';
            })();
        });

    $registrar = new PageRouteRegistrar(app('router'));

    $registrar->register([
        new PageDefinition(
            componentName: 'app/feed/page',
            urlPattern: 'feed',
        ),
        new PageDefinition(
            componentName: 'app/photo/[id]/page',
            urlPattern: 'photo/{id}',
            isDynamic: true,
            interceptRoutes: [
                ['slot' => 'modal', 'component' => 'app/@modal/(.)photo/[id]/page', 'interceptedUrl' => 'photo/{id}'],
            ],
        ),
    ]);

    $this->get('/photo/123', [
        Header::X_RSC => 'true',
        'Accept' => '*/*',
        Header::X_RSC_INTERCEPT => 'modal',
        Header::X_RSC_REFERER => '/feed',
    ])->assertStatus(200);
});

test('intercept without referer renders just the interceptor', function () {
    $this->bridgeMock
        ->shouldReceive('rscStream')
        ->once()
        ->withArgs(function (string $component) {
            // Fallback: render just the interceptor component
            return $component === 'app/@modal/(.)photo/[id]/page';
        })
        ->andReturnUsing(function () {
            return (function () {
                yield ['clientChunks' => [], 'metadata' => null];
                yield '0:["$","div",null,{"children":"Photo modal"}]';
            })();
        });

    $registrar = new PageRouteRegistrar(app('router'));

    $registrar->register([
        new PageDefinition(
            componentName: 'app/photo/[id]/page',
            urlPattern: 'photo/{id}',
            isDynamic: true,
            interceptRoutes: [
                ['slot' => 'modal', 'component' => 'app/@modal/(.)photo/[id]/page', 'interceptedUrl' => 'photo/{id}'],
            ],
        ),
    ]);

    $this->get('/photo/123', [
        Header::X_RSC => 'true',
        'Accept' => '*/*',
        Header::X_RSC_INTERCEPT => 'modal',
    ])->assertStatus(200);
});

test('without intercept header renders normal page component', function () {
    $this->bridgeMock
        ->shouldReceive('rscStream')
        ->once()
        ->withArgs(function (string $component) {
            return $component === 'app/photo/[id]/page';
        })
        ->andReturnUsing(function () {
            return (function () {
                yield ['clientChunks' => [], 'metadata' => null];
                yield '0:["$","div",null,{"children":"Full Photo Page"}]';
            })();
        });

    $registrar = new PageRouteRegistrar(app('router'));

    $registrar->register([
        new PageDefinition(
            componentName: 'app/photo/[id]/page',
            urlPattern: 'photo/{id}',
            isDynamic: true,
            interceptRoutes: [
                ['slot' => 'modal', 'component' => 'app/@modal/(.)photo/[id]/page', 'interceptedUrl' => 'photo/{id}'],
            ],
        ),
    ]);

    $this->get('/photo/123', [
        Header::X_RSC => 'true',
        'Accept' => '*/*',
    ])->assertStatus(200);
});

test('intercept with non-matching slot returns 404', function () {
    $registrar = new PageRouteRegistrar(app('router'));

    $registrar->register([
        new PageDefinition(
            componentName: 'app/photo/[id]/page',
            urlPattern: 'photo/{id}',
            isDynamic: true,
            interceptRoutes: [
                ['slot' => 'modal', 'component' => 'app/@modal/(.)photo/[id]/page', 'interceptedUrl' => 'photo/{id}'],
            ],
        ),
    ]);

    $this->get('/photo/123', [
        Header::X_RSC => 'true',
        'Accept' => '*/*',
        Header::X_RSC_INTERCEPT => 'nonexistent',
    ])->assertStatus(404);
});

test('intercept data is stored on route defaults', function () {
    $registrar = new PageRouteRegistrar(app('router'));

    $intercepts = [
        ['slot' => 'modal', 'component' => 'app/@modal/(.)photo/[id]/page', 'interceptedUrl' => 'photo/{id}'],
    ];

    $registrar->register([
        new PageDefinition(
            componentName: 'app/photo/[id]/page',
            urlPattern: 'photo/{id}',
            isDynamic: true,
            interceptRoutes: $intercepts,
        ),
    ]);

    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => ($r->defaults['_rsc_component'] ?? null) === 'app/photo/[id]/page');

    expect($route)->not->toBeNull()
        ->and($route->defaults['_rsc_intercepts'])->toBe($intercepts);
});
