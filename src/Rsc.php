<?php

namespace RscKit;

/**
 * The entry point an action reaches for.
 *
 *     Rsc::revalidate('orders');
 *
 * Says that whatever the action just changed makes that part of the page
 * stale. The answer to the action carries the re-rendered part back with it,
 * rather than the browser asking again once it has been told.
 */
class Rsc
{
    public static function revalidate(string ...$targets): void
    {
        app(Revalidation::class)->mark(...$targets);
    }

    /** Everything below the layouts, without re-rendering the layouts. */
    public const PAGE = 'page';
}
