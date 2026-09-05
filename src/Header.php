<?php

namespace RscKit;

final class Header
{
    public const X_RSC = 'X-RSC';

    public const X_RSC_VERSION = 'X-RSC-Version';

    public const X_RSC_LOCATION = 'X-RSC-Location';

    /**
     * Go here instead.
     *
     * Carries a redirect the client has to perform itself — a failed action,
     * and a navigation whose render redirected. Never a 3xx for either: fetch()
     * follows one transparently, so the client would get the destination's HTML
     * where it expected a Flight payload and decode it as one.
     */
    public const X_RSC_REDIRECT = 'X-RSC-Redirect';

    public const X_RSC_ACTION = 'X-RSC-Action';

    public const X_RSC_CONTENT_TYPE = 'X-RSC-Content-Type';

    public const X_RSC_INTERCEPT = 'X-RSC-Intercept';

    public const X_RSC_REFERER = 'X-RSC-Referer';

    /**
     * Request: the layout chain the client already has mounted, comma
     * separated and outermost first. The server renders from the first
     * layout that differs, so a navigation within the same section does not
     * resend the chrome around it.
     */
    /** Which part of the page to render on its own: all, page, or a slot name. */
    public const X_RSC_REVALIDATE = 'X-RSC-Revalidate';

    public const X_RSC_SEGMENTS = 'X-RSC-Segments';

    /** Response: which boundary depth the payload replaces. 0 is the whole document. */
    public const X_RSC_SEGMENT_DEPTH = 'X-RSC-Segment-Depth';

    /** Response: the layout chain this route has, for the client to send back next time. */
    public const X_RSC_LAYOUTS = 'X-RSC-Layouts';
}
