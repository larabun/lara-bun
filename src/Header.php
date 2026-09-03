<?php

namespace LaravelRsc;

final class Header
{
    public const X_RSC = 'X-RSC';

    public const X_RSC_VERSION = 'X-RSC-Version';

    public const X_RSC_LOCATION = 'X-RSC-Location';

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
