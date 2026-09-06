<?php

return [
    /*
     * Answering host calls from the renderer.
     *
     * The renderer owns the request and calls back here for data, for the
     * session, and to ask whether a route may render. Off unless a secret is
     * set, because this endpoint runs registered functions by name with none of
     * the application's routing in front of it — a default of "on and
     * unauthenticated" is the kind that ships.
     *
     * Keep it unreachable from outside as well as authenticated: bind the
     * renderer to loopback, or put this endpoint on a listener only it can
     * reach. It can serve a unix socket, which HTTP runs over unchanged and
     * which opens no port at all.
     */
    /*
     * Where the renderer listens.
     *
     * Anything Laravel does not route is handed to it, so an app parked in
     * ~/Herd works at its own .test domain with nothing else configured. The
     * fallback only runs when no real route matched, so the endpoint below and
     * the app's own routes always win.
     *
     * Set to null to turn it off — a deployment that puts the renderer in
     * front does not need it, and skips a hop.
     */
    'renderer_url' => env('RSC_RENDERER_URL', 'http://127.0.0.1:5173'),

    /*
     * Written by the dev server while it runs, and removed when it stops.
     *
     * Read before renderer_url, because a dev server chooses its port at
     * runtime: 5173 is the most contended port on a developer's machine, and
     * Vite moves to the next free one without saying so. Following the file
     * means another project running does not silently break this one.
     */
    'hot_file' => env('RSC_HOT_FILE', public_path('rsc-hot')),
    'renderer_timeout' => (float) env('RSC_RENDERER_TIMEOUT', 60),

    'host_call_path' => env('RSC_HOST_CALL_PATH', '/__rsc/host-call'),
    'host_call_secret' => env('RSC_HOST_CALL_SECRET'),

    /*
     * Name of the global your server components call to reach PHP.
     *
     * The Vite plugin and the generated server actions both read it from here,
     * so it is written down once. Changing it changes what app code calls.
     */
    'host_global' => env('RSC_HOST_GLOBAL', 'rpc'),
];
