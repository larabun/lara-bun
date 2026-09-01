---
name: pest-testing
description: "Tests the LaraBun package using Pest 4. Activates when writing tests, debugging test failures, adding assertions, or when the user mentions test, TDD, expects, or coverage."
license: MIT
metadata:
  author: larabun
---

# Pest Testing for LaraBun

## When to Apply

Activate this skill when:

- Creating new tests (unit or feature)
- Modifying existing tests
- Debugging test failures
- Verifying package behavior

## Test Structure

- `tests/Unit/` — Pure unit tests (no Laravel app boot)
- `tests/Feature/` — Feature tests (full Laravel app, Mockery for BunBridge)

## Running Tests

```bash
# From the package root (/Users/ramonmalcolm/Herd/lara-bun) — standalone testbench suite
vendor/bin/pest --compact                           # all tests
vendor/bin/pest tests/Feature/RouteInterceptionTest.php  # specific file
vendor/bin/pest --filter="intercept"                # by name
```

## Key Patterns

### Mocking BunBridge

Feature tests mock BunBridge to avoid needing a running Bun worker:

```php
beforeEach(function () {
    $this->bridgeMock = Mockery::mock(BunBridge::class);
    $this->app->instance(BunBridge::class, $this->bridgeMock);
});

test('example', function () {
    $this->bridgeMock
        ->shouldReceive('rscStream')
        ->once()
        ->withArgs(function (string $component) {
            return $component === 'app/page';
        })
        ->andReturnUsing(function () {
            return (function () {
                yield ['clientChunks' => [], 'metadata' => null];
                yield '0:["$","div",null,{}]';
            })();
        });

    Route::get('/test', fn () => rsc('app/page'));
    $this->get('/test', [Header::X_RSC => 'true'])->assertStatus(200);
});
```

### PageScanner Tests

Use temp directories for filesystem-based scanner tests:

```php
beforeEach(function () {
    $this->appDir = sys_get_temp_dir().'/rsc-test-'.uniqid();
    mkdir($this->appDir, 0755, true);
});

afterEach(function () {
    // Recursive cleanup
});
```

### PageDefinition Construction

All parameters have defaults — use named params for clarity:

```php
new PageDefinition(
    componentName: 'app/photo/[id]/page',
    urlPattern: 'photo/{id}',
    isDynamic: true,
    interceptRoutes: [
        ['slot' => 'modal', 'component' => 'app/@modal/(.)photo/[id]/page', 'interceptedUrl' => 'photo/{id}'],
    ],
);
```

## Critical Tests

- `BunBridgeStreamOrderTest` — Prevents regression of eager stream-start read
- `PageScannerInterceptTest` — Verifies intercept pattern detection
- `RouteInterceptionTest` — Verifies server-side intercept handling
- `RscResponseLayoutTest` — Verifies layout/loadings/slots pipeline

## After Writing Tests

Always run `vendor/bin/pint --dirty --format agent` from the test app directory to format PHP.
