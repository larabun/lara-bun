<?php

/**
 * How much of a route's layout chain the client can keep.
 *
 * The client sends what it is holding; everything the two agree on from the
 * root down is still mounted and does not need re-rendering. Getting this
 * wrong is not a visible error — too high and the payload is missing layouts
 * the client never had, too low and the navigation resends chrome for nothing.
 */

use LaravelRsc\RscResponse;

$chain = ['app/layout', 'app/docs/layout', 'app/docs/guides/layout'];

test('a client holding nothing gets the whole document', function () use ($chain) {
    expect(RscResponse::commonLayoutDepth(null, $chain))->toBe(0)
        ->and(RscResponse::commonLayoutDepth('', $chain))->toBe(0);
});

test('an identical chain means only the page changed', function () use ($chain) {
    expect(RscResponse::commonLayoutDepth(implode(',', $chain), $chain))->toBe(3);
});

test('stops at the first layout that differs', function () use ($chain) {
    // Same root, different section: everything from app/docs/layout down is new.
    expect(RscResponse::commonLayoutDepth('app/layout,app/blog/layout', $chain))->toBe(1);
});

test('a different root layout shares nothing', function () use ($chain) {
    expect(RscResponse::commonLayoutDepth('app/other/layout', $chain))->toBe(0);
});

test('a client holding a shorter chain keeps only what it has', function () use ($chain) {
    expect(RscResponse::commonLayoutDepth('app/layout', $chain))->toBe(1);
});

test('a client holding a deeper chain keeps only the shared prefix', function () use ($chain) {
    // Navigating up: the client has more layouts than this route uses.
    $deeper = implode(',', [...$chain, 'app/docs/guides/deep/layout']);

    expect(RscResponse::commonLayoutDepth($deeper, $chain))->toBe(3);
});

test('a route with no layouts never claims a depth', function () {
    expect(RscResponse::commonLayoutDepth('app/layout', []))->toBe(0);
});

test('tolerates whitespace and empty entries from a hand-built header', function () use ($chain) {
    expect(RscResponse::commonLayoutDepth(' app/layout , app/docs/layout ', $chain))->toBe(2);
});
