<?php

/**
 * What an action says it invalidated.
 *
 * An action knows what it changed and the page does not, so marking it is the
 * only way the answer can carry the re-rendered part back — instead of the
 * browser being told what went stale and asking for it in a second request.
 */

use LaravelRsc\Revalidation;
use LaravelRsc\Rsc;

test('nothing is marked until something marks it', function () {
    // The right default: most actions return what changed and the caller sets
    // it, which needs no re-render at all.
    expect(app(Revalidation::class)->targets())->toBe([]);
});

test('an action marks what it made stale', function () {
    Rsc::revalidate('orders');

    expect(app(Revalidation::class)->targets())->toBe(['orders']);
});

test('several targets can be marked at once, or over several calls', function () {
    Rsc::revalidate('orders', 'invoices');
    Rsc::revalidate('summary');

    expect(app(Revalidation::class)->targets())->toBe(['orders', 'invoices', 'summary']);
});

test('marking the same target twice asks for it once', function () {
    // Two callables in one action can both touch the orders table; rendering
    // it twice would be work for the same answer.
    Rsc::revalidate('orders');
    Rsc::revalidate('orders');

    expect(app(Revalidation::class)->targets())->toBe(['orders']);
});

test('flushing hands the marks over and forgets them', function () {
    // Consumed once per callback response, so a later call in the same action
    // starts clean rather than re-reporting what has already been sent.
    Rsc::revalidate('orders');

    expect(app(Revalidation::class)->flush())->toBe(['orders'])
        ->and(app(Revalidation::class)->targets())->toBe([]);
});

test('it is resolved per request, not shared between them', function () {
    // Bound scoped rather than singleton: under a persistent runtime a
    // singleton would carry one request's marks into the next.
    Rsc::revalidate('orders');
    app()->forgetScopedInstances();

    expect(app(Revalidation::class)->targets())->toBe([]);
});
