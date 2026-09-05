<?php

use RscKit\Header;
use RscKit\PageDefinition;
use RscKit\PageRouteRegistrar;
use RscKit\RuntimeBridge;

beforeEach(function () {
    $this->bridgeMock = Mockery::mock(RuntimeBridge::class);
    $this->app->instance(RuntimeBridge::class, $this->bridgeMock);
});

test('intercept with referer renders the interceptor alone, for the slot', function () {
    // The page underneath is already mounted and still correct. Re-rendering it
    // to place the modal rebuilds everything below the layout declaring the
    // slot, so opening a modal from a half-filled form throws the form away.
    $this->bridgeMock
        ->shouldReceive('rscRevalidate')
        ->once()
        ->withArgs(function (string $target, array $page) {
            return $target === 'modal'
                // Pointed at the interceptor rather than the slot's default,
                // and given the target url's params rather than the page's.
                && $page['parallelSlots'] === ['modal' => 'app/@modal/(.)photo/[id]/page']
                && $page['props']['id'] === '123';
        })
        ->andReturn(['rscPayload' => '0:["$","div",null,{"children":"Photo modal"}]']);

    $this->bridgeMock->shouldNotReceive('rscStream');

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
    ])
        ->assertStatus(200)
        // Says which region it is, so the client fills the slot with it rather
        // than replacing a segment of the page.
        ->assertHeader(Header::X_RSC_REVALIDATE, 'modal');
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
