<?php

/**
 * The package holds singletons, and under a persistent runtime a singleton
 * outlives the request that first populated it.
 *
 * Laravel Octane — with FrankenPHP, RoadRunner or Swoole — boots the
 * application once and serves many requests from it. Anything a singleton
 * cached during the first request is still there for the hundredth, served to
 * whoever made it. Under PHP-FPM the same code is per-request and none of this
 * can happen, which is why the rest of the suite cannot see it: every test is
 * its own request.
 */

use LaravelRsc\CallableRegistry;

/** Something request-shaped: resolved per request, different every time. */
class CurrentVisitor
{
    public function __construct(public string $name) {}
}

/** A user callable that takes it in the constructor, as they ordinarily would. */
class GreetsTheVisitor
{
    public function __construct(private CurrentVisitor $visitor) {}

    public function __invoke(): string
    {
        return $this->visitor->name;
    }
}

/** Swap what the container hands out, the way a new request would. */
function arrivingVisitor(string $name): void
{
    app()->bind(CurrentVisitor::class, fn () => new CurrentVisitor($name));
}

test('a callable does not serve one request the instance it built for another', function () {
    // The registry is bound as a singleton, so on Octane this same object
    // serves every request the worker handles. Caching resolved instances in
    // it hands the first visitor's dependencies to the second.
    $registry = new CallableRegistry(app());
    $registry->register('whoami', GreetsTheVisitor::class);

    arrivingVisitor('ada');
    expect($registry->execute('whoami', []))->toBe('ada');

    // A second request arrives at the same worker.
    arrivingVisitor('grace');

    expect($registry->execute('whoami', []))->toBe('grace');
});

test('discovery is still not repeated, because it is not request state', function () {
    // Only the instances were unsafe to keep. What was registered is a property
    // of the code, not of whoever is asking, and rediscovering it per request
    // would be the wrong way to fix this.
    $registry = new CallableRegistry(app());
    $registry->register('whoami', GreetsTheVisitor::class);

    arrivingVisitor('ada');
    $registry->execute('whoami', []);

    expect($registry->names())->toBe(['whoami'])
        ->and($registry->hasCallables())->toBeTrue();
});
