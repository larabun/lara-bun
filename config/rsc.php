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
