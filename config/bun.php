<?php

use LaraBun\Support\System;

return [
    // Explicit path to the Bun executable. Leave null to auto-discover
    // (Homebrew / /usr/local / ~/.bun / PATH). Relative paths resolve from the
    // app base path. Set this on hosts where Bun is vendored into the app —
    // e.g. Laravel Cloud, where `php artisan bun:install` downloads a static
    // binary the runtime image would not otherwise have.
    'binary' => env('BUN_BINARY'),

    // Transport between PHP and the Bun worker. 'unix' (default) uses a local
    // Unix domain socket — fastest, and lockable to the owner on shared hosts.
    // 'tcp' uses a loopback connection instead; use it where PHP and the worker
    // may not share a filesystem for the socket (e.g. Laravel Cloud, split
    // containers). With multiple workers, main ports are host:port..port+N-1 and
    // callback ports follow at port+N..port+2N-1.
    'transport' => env('BUN_TRANSPORT', 'unix'),
    'host' => env('BUN_HOST', '127.0.0.1'),
    'port' => (int) env('BUN_PORT', 7940),

    'socket_path' => env('BUN_BRIDGE_SOCKET', '/tmp/bun-bridge.sock'),
    'functions_dir' => env('BUN_BRIDGE_FUNCTIONS_DIR', resource_path('bun')),

    // Number of Bun worker processes. Each is a separate event loop, so this is
    // the primary throughput/concurrency knob. Defaults to one per CPU core
    // (capped) when BUN_WORKERS is not set; raise it on larger instances.
    'workers' => (int) env('BUN_WORKERS', 0) ?: System::defaultWorkerCount(),

    'rsc' => [
        'enabled' => env('BUN_RSC_ENABLED', true),

        // The built RSC server bundle the worker loads (@vitejs/plugin-rsc output).
        'bundle' => env('BUN_RSC_BUNDLE', base_path('bootstrap/rsc/vite/dist/rsc/index.js')),
        'source_dir' => env('BUN_RSC_SOURCE_DIR', resource_path('js/rsc')),

        // Public dir + URL for the browser-facing client bundle. Served directly
        // by the web server (never through PHP); `assets_url` is the Vite base.
        'assets_dir' => env('BUN_RSC_ASSETS_DIR', public_path('build/rsc-vite')),
        'assets_url' => env('BUN_RSC_ASSETS_URL', '/build/rsc-vite/'),

        'callback_timeout' => 5,
        'stream_timeout' => (int) env('BUN_RSC_STREAM_TIMEOUT', 30),
        'static_path' => env('BUN_RSC_STATIC_PATH', storage_path('framework/rsc-static')),
        'body_size_limit' => env('BUN_RSC_BODY_SIZE_LIMIT', '1mb'),
    ],

    'entry_points' => array_filter(
        explode(',', env('BUN_BRIDGE_ENTRY_POINTS', '')),
    ),
];
