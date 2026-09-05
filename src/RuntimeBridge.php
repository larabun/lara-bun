<?php

namespace LaravelRsc;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Socket;

class RuntimeBridge
{
    /**
     * Response headers the action currently being streamed asked for.
     *
     * Reset when one starts and read by the controller as soon as the first
     * chunk arrives, which is before it can build its response — the window is
     * one action, on one bridge, within one request.
     *
     * @var list<array{0: string, 1: string}>
     */
    private array $actionHeaders = [];

    /** @var string[] */
    private array $socketPaths = [];

    /**
     * Pool of available (idle) sockets per worker index.
     *
     * @var array<int, Socket[]>
     */
    private array $pool = [];

    /** @var array<int, Socket[]> */
    private array $cbPool = [];

    /** @var string[] */
    private array $cbSocketPaths = [];

    /** 'unix' | 'tcp' */
    private string $transport = 'unix';

    private string $host = '127.0.0.1';

    /** @var array<int, int> per-worker main TCP port */
    private array $mainPorts = [];

    /** @var array<int, int> per-worker callback TCP port */
    private array $cbPorts = [];

    private int $cbIdCounter = 0;

    private int $workerCount;

    private int $currentWorker;

    private int $maxFrameSize;

    public function __construct()
    {
        if (! extension_loaded('sockets')) {
            throw new RuntimeException('The sockets extension is required. Enable it in php.ini.');
        }

        $this->workerCount = max(1, (int) config('rsc.workers', 1));
        $this->currentWorker = $this->workerCount > 1 ? random_int(0, $this->workerCount - 1) : 0;
        $this->maxFrameSize = self::parseSize(config('rsc.body_size_limit', '1mb'));
        $this->transport = config('rsc.transport', 'unix') === 'tcp' ? 'tcp' : 'unix';

        if ($this->transport === 'tcp') {
            $this->host = (string) config('rsc.host', '127.0.0.1');
            $basePort = (int) config('rsc.port', 7940);

            for ($i = 0; $i < $this->workerCount; $i++) {
                $ports = self::tcpPorts($basePort, $this->workerCount, $i);
                $this->mainPorts[$i] = $ports['main'];
                $this->cbPorts[$i] = $ports['cb'];
            }

            return;
        }

        $basePath = (string) config('rsc.socket_path', '/tmp/laravel-rsc.sock');

        for ($i = 0; $i < $this->workerCount; $i++) {
            $this->socketPaths[] = self::unixSocketPath($basePath, $i);
            $this->cbSocketPaths[] = self::unixSocketPath($basePath, $i).'.cb';
        }
    }

    /**
     * Socket path for one worker, shared by PHP and the serve command so both
     * sides agree.
     *
     * Always indexed, including for a single worker. The count is derived from
     * CPU/memory detection that can legitimately differ between the CLI and
     * PHP-FPM, so it must not decide the *naming* — otherwise the two processes
     * look for different files and every request fails with a socket-not-found
     * telling you to start a worker that is already running.
     */
    public static function unixSocketPath(string $basePath, int $index): string
    {
        return preg_replace('/\.sock$/', '', $basePath)."-{$index}.sock";
    }

    /**
     * Every socket file this bridge expects a worker to create.
     *
     * Callers that need to clear stale sockets ask for them here rather than
     * rebuilding the path scheme, which is what let the naming drift before.
     * Empty under the TCP transport, which has no files.
     *
     * @return list<string>
     */
    public function socketFiles(): array
    {
        return [...$this->socketPaths, ...$this->cbSocketPaths];
    }

    /**
     * Per-worker TCP port assignment, shared by PHP and the serve command so
     * both sides agree. Main ports occupy [base, base+N); callback ports follow
     * at [base+N, base+2N), so the two ranges never overlap.
     *
     * @return array{main: int, cb: int}
     */
    public static function tcpPorts(int $basePort, int $workerCount, int $index): array
    {
        return [
            'main' => $basePort + $index,
            'cb' => $basePort + $workerCount + $index,
        ];
    }

    public function call(string $function, array $args = []): mixed
    {
        $response = $this->send(json_encode([
            'type' => 'call',
            'function' => $function,
            'args' => $args,
        ], JSON_THROW_ON_ERROR));

        if (isset($response['error'])) {
            throw new RuntimeException("Bun error: {$response['error']}");
        }

        return $response['result'] ?? null;
    }

    /**
     * Render a PPR shell — page with mock php() so async components suspend.
     *
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     * @return array{shellHtml: string, clientChunks: string[], timedOut: bool, usedDynamicApis: bool}
     */
    /**
     * Render the static shell of a page, to decide what can be frozen.
     *
     * `$pageKey` is the url this shell will be served for, when it is served
     * for exactly one — and it decides whether the page's params settle. A
     * route that listed its urls is being rendered for one of them, so the
     * page can read them and be frozen whole; a route that listed none is
     * being rendered for the pattern, where any value would be an invention,
     * and its params never settle so the page suspends where it reads them.
     *
     * Omitting it made every shell a pattern shell. A page that awaits its
     * params then suspends immediately, and what gets frozen is its loading
     * fallback with no document around it and no bootstrap inside it — so
     * nothing hydrates, nothing fills, and the page is blank forever.
     */
    public function rscPprShell(string $component, array $props = [], array $layouts = [], array $loadings = [], array $parallelSlots = [], string $pageKey = ''): array
    {
        $response = $this->send(json_encode([
            'type' => 'rsc-ppr-shell',
            'component' => $component,
            'props' => $props,
            'layouts' => $layouts, 'loadings' => $loadings ?? [], 'parallelSlots' => $parallelSlots ?? [],
            'pageKey' => $pageKey,
        ], JSON_THROW_ON_ERROR));

        if (isset($response['error'])) {
            throw new RuntimeException("Bun PPR shell error: {$response['error']}");
        }

        return $response['result'];
    }

    /**
     * Render RSC without callback socket — for build-time prerendering.
     *
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     * @return array{body: string, rscPayload: string, clientChunks: string[], usedDynamicApis?: bool}
     */
    /**
     * Flight payload only, with no HTML pass.
     *
     * The segment variant of a prerendered route needs the payload and nothing
     * else; rscWithoutCallbacks would render the HTML too and discard it.
     *
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     * @return array{rscPayload: string}
     */
    /**
     * Render one part of a page on its own.
     *
     * For a client asking to refresh something nobody mutated anything to
     * earn — a button, a poll, a message saying that table has moved. What an
     * action invalidated does not come through here: that travels back inside
     * the action's own answer.
     *
     * @param  array{component: string, props: array<string, mixed>, layouts: array<int, mixed>, loadings: array<int, string>, parallelSlots: array<string, string>}  $page
     * @return array{rscPayload: string}
     */
    public function rscRevalidate(string $target, array $page): array
    {
        return $this->rsc(
            $page['component'],
            $page['props'],
            $page['layouts'],
            $page['loadings'],
            $page['parallelSlots'],
            $target,
        );
    }

    public function rscPayload(string $component, array $props, array $layouts, int $from, string $pageKey, array $loadings = [], array $parallelSlots = []): array
    {
        $response = $this->send(json_encode([
            'type' => 'rsc-payload',
            'component' => $component,
            'props' => $props,
            'layouts' => $layouts,
            'loadings' => $loadings,
            'parallelSlots' => $parallelSlots,
            'from' => $from,
            'pageKey' => $pageKey,
        ], JSON_THROW_ON_ERROR));

        if (isset($response['error'])) {
            throw new RuntimeException("Bun RSC error: {$response['error']}");
        }

        return $response['result'];
    }

    public function rscWithoutCallbacks(string $component, array $props = [], array $layouts = [], array $loadings = [], array $parallelSlots = [], int $from = 0, string $pageKey = '', bool $bootstrap = true): array
    {
        $response = $this->send(json_encode([
            'type' => 'rsc',
            'component' => $component,
            'props' => $props,
            'layouts' => $layouts, 'loadings' => $loadings ?? [], 'parallelSlots' => $parallelSlots ?? [],
            'from' => $from,
            'pageKey' => $pageKey,
            'bootstrap' => $bootstrap,
        ], JSON_THROW_ON_ERROR));

        if (isset($response['error'])) {
            throw new RuntimeException("Bun RSC error: {$response['error']}");
        }

        return $response['result'];
    }

    /**
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     * @return array{body: string, rscPayload: string, clientChunks: string[], usedDynamicApis?: bool}
     */
    /**
     * Render a page, or one named part of it.
     *
     * A target routes the same request to a partial render. It shares this
     * path rather than having its own because a slot can call back into the
     * host exactly like a page can — and without the callback channel below,
     * any slot that fetches anything fails.
     */
    public function rsc(string $component, array $props = [], array $layouts = [], array $loadings = [], array $parallelSlots = [], ?string $target = null): array
    {
        $registry = app(CallableRegistry::class);
        $hasCallbacks = $registry->hasCallables();

        $index = $this->currentWorker++ % $this->workerCount;
        $mainSocket = $this->checkout($index);
        $callbackId = $hasCallbacks ? $this->nextCallbackId() : null;
        $callbackSocket = null;
        $callbackBuffer = '';

        try {
            if ($hasCallbacks && $callbackId) {
                $callbackSocket = $this->checkoutCallback($index, $callbackId);
            }

            $this->writeFrame($mainSocket, json_encode([
                'type' => 'rsc',
                // The url and headers, like every other render path sends. Left
                // out, the worker opens no request scope, and the searchParams
                // every page and slot is handed rejects instead of resolving —
                // React never finishes serializing it, the worker never
                // answers, and PHP reports a callback timeout five seconds
                // later with nothing in either log to say why.
                ...$this->requestEnvelope(),
                'component' => $component,
                'props' => $props,
                'layouts' => $layouts, 'loadings' => $loadings ?? [], 'parallelSlots' => $parallelSlots ?? [],
                'callbackId' => $callbackId,
                'target' => $target,
            ], JSON_THROW_ON_ERROR));

            while (true) {
                $read = [$mainSocket];

                if ($callbackSocket !== null) {
                    $read[] = $callbackSocket;
                }

                $write = [];
                $except = [];
                $timeout = (int) config('rsc.callback_timeout', 5);
                $changed = socket_select($read, $write, $except, $timeout);

                if ($changed === false) {
                    throw new RuntimeException('socket_select() failed: '.socket_strerror(socket_last_error()));
                }

                if ($changed === 0) {
                    throw new RuntimeException("RSC callback timed out after {$timeout} seconds");
                }

                if ($callbackSocket !== null && in_array($callbackSocket, $read, true)) {
                    $this->handleCallbackData($callbackSocket, $callbackBuffer, $registry);
                }

                if (in_array($mainSocket, $read, true)) {
                    $response = $this->readFrame($mainSocket);
                    $this->release($index, $mainSocket);
                    $mainSocket = null;

                    if (isset($response['error'])) {
                        throw new RuntimeException("Bun RSC error: {$response['error']}");
                    }

                    if (! isset($response['result']) || ! is_array($response['result'])) {
                        throw new RuntimeException('Invalid RSC response from Bun');
                    }

                    return $response['result'];
                }
            }
        } catch (\Throwable $e) {
            if ($mainSocket !== null) {
                @socket_close($mainSocket);
            }

            throw $e;
        } finally {
            if ($callbackSocket !== null) {
                // $mainSocket is nulled only on the successful return path; the
                // catch above closes it on error without nulling. A non-empty
                // buffer means a partial callback frame remains.
                $this->releaseCallback($index, $callbackSocket, $mainSocket === null && $callbackBuffer === '');
            }
        }
    }

    /**
     * Stream the raw Flight payload for SPA navigation.
     *
     * Uses a dedicated reverse-connection socket so Bun writes via
     * Bun.connect() which flushes immediately, unlike Bun.listen
     * handler sockets which buffer writes internally.
     *
     * The first yielded value is always an array of browser chunk paths
     * (clientChunks). All subsequent yields are Flight payload strings.
     *
     * @return \Generator<int, string[]|string, void, void>
     */
    /**
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     */
    public function rscStream(string $component, array $props = [], array $layouts = [], array $loadings = [], array $parallelSlots = [], array $slotOverrides = [], int $from = 0, string $pageKey = ''): \Generator
    {
        $registry = app(CallableRegistry::class);
        $hasCallbacks = $registry->hasCallables();

        $index = $this->currentWorker++ % $this->workerCount;
        $mainSocket = $this->checkout($index);
        $callbackId = $hasCallbacks ? $this->nextCallbackId() : null;
        $callbackSocket = null;

        $callbackBuffer = '';

        try {
            if ($hasCallbacks && $callbackId) {
                $callbackSocket = $this->checkoutCallback($index, $callbackId);
            }

            $this->writeFrame($mainSocket, json_encode([
                'type' => 'rsc-stream',
                ...$this->requestEnvelope(),
                'component' => $component,
                'props' => $props,
                'layouts' => $layouts, 'loadings' => $loadings ?? [], 'parallelSlots' => $parallelSlots ?? [],
                'slotOverrides' => $slotOverrides !== [] ? $slotOverrides : null,
                'callbackId' => $callbackId,
                'from' => $from,
                'pageKey' => $pageKey,
            ], JSON_THROW_ON_ERROR));

            // Read stream-start before the main loop so HTTP headers flush
            // immediately, but service callbacks while waiting — metadata
            // resolution on the worker may itself issue php() calls, which would
            // otherwise deadlock against a bare readFrame() here.
            $startFrame = $this->readStartFrame($mainSocket, $callbackSocket, $registry, $callbackBuffer);
            $this->throwIfAuthError($startFrame);
            $this->throwIfRedirect($startFrame);

            if (isset($startFrame['error'])) {
                throw new RuntimeException("Bun RSC stream error: {$startFrame['error']}");
            }

            yield [
                'clientChunks' => $startFrame['clientChunks'] ?? [],
                'metadata' => $startFrame['metadata'] ?? null,
                // Headers a middleware set. They arrive on the start frame
                // because that is the last thing the worker sends before the
                // body — PHP has not written its status line yet, so there is
                // still an answer to put them on.
                'headers' => $this->frameHeaders($startFrame),
                // What the worker actually rendered, which is not always what
                // was asked: an interceptor can force a wider render.
                'segmentDepth' => $startFrame['segmentDepth'] ?? 0,
            ];

            $idleTimeout = $this->streamIdleTimeout();

            while (true) {
                $read = [$mainSocket];

                if ($callbackSocket !== null) {
                    $read[] = $callbackSocket;
                }

                $write = [];
                $except = [];
                $changed = socket_select($read, $write, $except, $idleTimeout);

                if ($changed === false) {
                    throw new RuntimeException('socket_select() failed: '.socket_strerror(socket_last_error()));
                }

                if ($changed === 0) {
                    throw new RuntimeException("Bun RSC stream exceeded {$idleTimeout}s idle timeout");
                }

                // Always drain the main socket first — yield stream chunks to
                // the browser before blocking on potentially slow callbacks.
                if (in_array($mainSocket, $read, true)) {
                    $frame = $this->readFrame($mainSocket);

                    $this->throwIfAuthError($frame);
                    $this->throwIfRedirect($frame);

                    if (isset($frame['error'])) {
                        throw new RuntimeException("Bun RSC stream error: {$frame['error']}");
                    }

                    $type = $frame['type'] ?? '';

                    if ($type === 'stream-chunk') {
                        yield $frame['data'] ?? '';

                        continue;
                    }

                    if ($type === 'stream-end') {
                        $this->release($index, $mainSocket);
                        $mainSocket = null;

                        break;
                    }
                }

                if ($callbackSocket !== null && in_array($callbackSocket, $read, true)) {
                    // Before processing the callback (which may block), check
                    // if the main socket also has data and drain it first.
                    $mainCheck = [$mainSocket];
                    $w = [];
                    $e = [];

                    while (socket_select($mainCheck, $w, $e, 0) > 0) {
                        $frame = $this->readFrame($mainSocket);
                        $this->throwIfAuthError($frame);
                        $this->throwIfRedirect($frame);

                        if (isset($frame['error'])) {
                            throw new RuntimeException("Bun RSC stream error: {$frame['error']}");
                        }

                        $type = $frame['type'] ?? '';

                        if ($type === 'stream-chunk') {
                            yield $frame['data'] ?? '';
                        }

                        if ($type === 'stream-end') {
                            $this->release($index, $mainSocket);
                            $mainSocket = null;

                            break 2;
                        }

                        $mainCheck = [$mainSocket];
                        $w = [];
                        $e = [];
                    }

                    $this->handleCallbackData($callbackSocket, $callbackBuffer, $registry);
                }
            }
        } finally {
            if ($mainSocket !== null) {
                @socket_close($mainSocket);
            }

            if ($callbackSocket !== null) {
                // $mainSocket is nulled only on clean completion; a non-empty
                // buffer means a partial callback frame is still pending. Either
                // makes the socket unsafe to pool.
                $this->releaseCallback($index, $callbackSocket, $mainSocket === null && $callbackBuffer === '');
            }
        }
    }

    /**
     * Stream HTML for initial page loads with Suspense support.
     *
     * React renders the shell (with Suspense fallbacks) immediately, then
     * streams completion scripts as async content resolves. The Flight
     * payload for hydration is sent as the final yield.
     *
     * Yields:
     *  1st: array{clientChunks: string[]}
     *  middle: string (HTML chunks)
     *  last: array{rscPayload: string}
     *
     * @return \Generator<int, array{clientChunks?: string[], rscPayload?: string}|string, void, void>
     */
    /**
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     */
    public function rscHtmlStream(string $component, array $props = [], array $layouts = [], array $loadings = [], array $parallelSlots = [], array $slotOverrides = [], ?string $nonce = null, string $pageKey = ''): \Generator
    {
        $registry = app(CallableRegistry::class);
        $hasCallbacks = $registry->hasCallables();

        $index = $this->currentWorker++ % $this->workerCount;
        $mainSocket = $this->checkout($index);
        $callbackId = $hasCallbacks ? $this->nextCallbackId() : null;
        $callbackSocket = null;

        $callbackBuffer = '';

        try {
            if ($hasCallbacks && $callbackId) {
                $callbackSocket = $this->checkoutCallback($index, $callbackId);
            }

            $this->writeFrame($mainSocket, json_encode([
                'type' => 'rsc-html-stream',
                ...$this->requestEnvelope(),
                'component' => $component,
                'props' => $props,
                'layouts' => $layouts, 'loadings' => $loadings ?? [], 'parallelSlots' => $parallelSlots ?? [],
                'slotOverrides' => $slotOverrides !== [] ? $slotOverrides : null,
                'callbackId' => $callbackId,
                'nonce' => $nonce,
                'pageKey' => $pageKey,
            ], JSON_THROW_ON_ERROR));

            // Read html-start before the main loop so HTTP headers flush
            // immediately, but service callbacks while waiting — metadata
            // resolution on the worker may itself issue php() calls, which would
            // otherwise deadlock against a bare readFrame() here.
            $startFrame = $this->readStartFrame($mainSocket, $callbackSocket, $registry, $callbackBuffer);
            $this->throwIfAuthError($startFrame);
            $this->throwIfRedirect($startFrame);

            if (isset($startFrame['error'])) {
                throw new RuntimeException("Bun RSC HTML stream error: {$startFrame['error']}");
            }

            yield [
                'clientChunks' => $startFrame['clientChunks'] ?? [],
                'metadata' => $startFrame['metadata'] ?? null,
                'headers' => $this->frameHeaders($startFrame),
            ];

            $idleTimeout = $this->streamIdleTimeout();

            while (true) {
                $read = [$mainSocket];

                if ($callbackSocket !== null) {
                    $read[] = $callbackSocket;
                }

                $write = [];
                $except = [];
                $changed = socket_select($read, $write, $except, $idleTimeout);

                if ($changed === false) {
                    throw new RuntimeException('socket_select() failed: '.socket_strerror(socket_last_error()));
                }

                if ($changed === 0) {
                    throw new RuntimeException("Bun RSC HTML stream exceeded {$idleTimeout}s idle timeout");
                }

                if (in_array($mainSocket, $read, true)) {
                    $frame = $this->readFrame($mainSocket);

                    $this->throwIfAuthError($frame);
                    $this->throwIfRedirect($frame);

                    if (isset($frame['error'])) {
                        throw new RuntimeException("Bun RSC HTML stream error: {$frame['error']}");
                    }

                    $type = $frame['type'] ?? '';

                    if ($type === 'html-chunk') {
                        yield $frame['data'] ?? '';

                        continue;
                    }

                    if ($type === 'html-end') {
                        yield ['rscPayload' => $frame['rscPayload'] ?? ''];
                        $this->release($index, $mainSocket);
                        $mainSocket = null;

                        break;
                    }
                }

                if ($callbackSocket !== null && in_array($callbackSocket, $read, true)) {
                    // Before processing the callback (which may block), drain
                    // any pending main socket frames so HTML chunks are flushed
                    // to the browser immediately.
                    $mainCheck = [$mainSocket];
                    $w = [];
                    $e = [];

                    while (socket_select($mainCheck, $w, $e, 0) > 0) {
                        $frame = $this->readFrame($mainSocket);
                        $this->throwIfAuthError($frame);
                        $this->throwIfRedirect($frame);

                        if (isset($frame['error'])) {
                            throw new RuntimeException("Bun RSC HTML stream error: {$frame['error']}");
                        }

                        $type = $frame['type'] ?? '';

                        if ($type === 'html-chunk') {
                            yield $frame['data'] ?? '';
                        }

                        if ($type === 'html-end') {
                            yield ['rscPayload' => $frame['rscPayload'] ?? ''];
                            $this->release($index, $mainSocket);
                            $mainSocket = null;

                            break 2;
                        }

                        $mainCheck = [$mainSocket];
                        $w = [];
                        $e = [];
                    }

                    $this->handleCallbackData($callbackSocket, $callbackBuffer, $registry);
                }
            }
        } finally {
            if ($mainSocket !== null) {
                @socket_close($mainSocket);
            }

            if ($callbackSocket !== null) {
                // $mainSocket is nulled only on clean completion; a non-empty
                // buffer means a partial callback frame is still pending. Either
                // makes the socket unsafe to pool.
                $this->releaseCallback($index, $callbackSocket, $mainSocket === null && $callbackBuffer === '');
            }
        }
    }

    /**
     * Execute a server action and stream the Flight result.
     *
     * Same streaming pattern as rscStream() but sends type "rsc-action"
     * with the action ID and encoded arguments body.
     *
     * Yields Flight payload strings (no metadata prefix — action responses
     * don't need clientChunks).
     *
     * @return \Generator<int, string, void, void>
     */
    public function rscAction(string $actionId, string $body, string $contentType = 'text/plain', ?array $page = null): \Generator
    {
        $this->actionHeaders = [];

        $registry = app(CallableRegistry::class);
        $hasCallbacks = $registry->hasCallables();
        $index = $this->currentWorker++ % $this->workerCount;
        $mainSocket = $this->checkout($index);
        $callbackId = $hasCallbacks ? $this->nextCallbackId() : null;
        $callbackSocket = null;
        $callbackBuffer = '';

        try {
            if ($hasCallbacks && $callbackId) {
                $callbackSocket = $this->checkoutCallback($index, $callbackId);
            }

            // The body travels as its own frame rather than inside the JSON
            // one. Frames are length-prefixed bytes, so a body needs no
            // encoding to survive; it was base64'd only because it used to
            // ride inside JSON, which cannot carry bytes that are not valid
            // UTF-8. That cost a third of the size in transit, two full copies
            // on each side, and made the frame limit measure the encoded
            // length — so a 900kB upload failed a 1MB limit.
            $this->writeFrame($mainSocket, json_encode([
                'type' => 'rsc-action',
                ...$this->requestEnvelope(),
                'actionId' => $actionId,
                'bodyEncoding' => 'binary',
                'bodyLength' => strlen($body),
                'contentType' => $contentType,
                'callbackId' => $callbackId,
                // Only when the browser said where it was: without it the
                // action still runs, it just cannot re-render anything.
                'page' => $page,
            ], JSON_THROW_ON_ERROR));

            // Only when there is one: a zero-length frame is not a frame.
            if ($body !== '') {
                $this->writeFrame($mainSocket, $body);
            }

            $idleTimeout = $this->streamIdleTimeout();

            while (true) {
                $read = [$mainSocket];
                if ($callbackSocket !== null) {
                    $read[] = $callbackSocket;
                }
                $write = [];
                $except = [];
                $changed = socket_select($read, $write, $except, $idleTimeout);

                if ($changed === false) {
                    throw new RuntimeException('socket_select() failed');
                }

                if ($changed === 0) {
                    throw new RuntimeException("Bun RSC action exceeded {$idleTimeout}s idle timeout");
                }

                if (in_array($mainSocket, $read, true)) {
                    $frame = $this->readFrame($mainSocket);

                    if (isset($frame['redirect'])) {
                        throw new RscRedirectException(
                            $frame['redirect'],
                            (int) ($frame['redirectStatus'] ?? 302),
                            $this->frameHeaders($frame),
                        );
                    }
                    $this->throwIfAuthError($frame);
                    $this->throwIfRedirect($frame);
                    $this->throwIfValidationError($frame);

                    if (isset($frame['error'])) {
                        throw new RuntimeException("Bun RSC action error: {$frame['error']}");
                    }

                    $type = $frame['type'] ?? '';
                    if ($type === 'action-start') {
                        // An action's whole point may be the cookie it leaves
                        // behind. Held rather than yielded: this generator
                        // yields body chunks, and the controller reads them
                        // straight after the first one, before anything else
                        // on this bridge can run.
                        $this->actionHeaders = $this->frameHeaders($frame);

                        continue;
                    }
                    if ($type === 'action-chunk') {
                        yield $frame['data'] ?? '';

                        continue;
                    }
                    if ($type === 'action-end') {
                        $this->release($index, $mainSocket);
                        $mainSocket = null;
                        break;
                    }
                }

                if ($callbackSocket !== null && in_array($callbackSocket, $read, true)) {
                    $this->handleCallbackData($callbackSocket, $callbackBuffer, $registry);
                }
            }
        } finally {
            if ($mainSocket !== null) {
                @socket_close($mainSocket);
            }
            if ($callbackSocket !== null) {
                $this->releaseCallback($index, $callbackSocket, $mainSocket === null && $callbackBuffer === '');
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function list(): array
    {
        return $this->send('{"type":"list"}')['result'] ?? [];
    }

    public function ping(): bool
    {
        $anyAlive = false;

        // Iterate worker indices rather than $socketPaths, which is empty under
        // the TCP transport.
        for ($i = 0; $i < $this->workerCount; $i++) {
            $socket = $this->checkout($i);

            try {
                $this->writeFrame($socket, '{"type":"ping"}');
                $response = $this->readFrame($socket);
                $this->release($i, $socket);

                if (($response['type'] ?? null) === 'pong') {
                    $anyAlive = true;
                }
            } catch (RuntimeException) {
                socket_close($socket);
            }
        }

        return $anyAlive;
    }

    public function disconnect(): void
    {
        foreach ($this->pool as $index => $sockets) {
            foreach ($sockets as $socket) {
                socket_close($socket);
            }
        }

        $this->pool = [];
    }

    /**
     * Parse a human-readable size string (e.g. '25mb', '512kb') into bytes.
     *
     * Falls back to 1MB if the value is invalid.
     */
    public static function parseSize(string $size): int
    {
        $size = trim($size);

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(kb|mb|gb|b)?$/i', $size, $matches)) {
            $value = (float) $matches[1];
            $unit = strtolower($matches[2] ?? 'b');

            return (int) match ($unit) {
                'kb' => $value * 1024,
                'mb' => $value * 1024 * 1024,
                'gb' => $value * 1024 * 1024 * 1024,
                default => $value,
            };
        }

        return 1024 * 1024;
    }

    private function handleCallbackData(Socket $socket, string &$buffer, CallableRegistry $registry): void
    {
        $chunk = @socket_read($socket, 65536, PHP_BINARY_READ);

        if ($chunk === false || $chunk === '') {
            return;
        }

        $buffer .= $chunk;

        while (strlen($buffer) >= 4) {
            $frameLength = unpack('N', substr($buffer, 0, 4))[1];

            if ($frameLength <= 0 || $frameLength > $this->maxFrameSize) {
                $buffer = '';

                return;
            }

            if (strlen($buffer) < 4 + $frameLength) {
                return;
            }

            $json = substr($buffer, 4, $frameLength);
            $buffer = substr($buffer, 4 + $frameLength);

            $request = json_decode($json, true);

            if (! is_array($request) || ($request['type'] ?? '') !== 'callback') {
                continue;
            }

            $id = $request['id'] ?? '';
            $function = $request['function'] ?? '';
            $args = $request['args'] ?? [];

            try {
                $result = $registry->execute($function, $args);

                // Anything the callable marked stale rides back with its
                // result, so the worker can re-render it while it still has
                // the action open — rather than the browser being told and
                // asking again.
                $revalidate = app(Revalidation::class)->flush();

                $response = json_encode(
                    $revalidate === []
                        ? ['id' => $id, 'result' => $result]
                        : ['id' => $id, 'result' => $result, 'revalidate' => $revalidate],
                    JSON_THROW_ON_ERROR
                );
            } catch (AuthenticationException $e) {
                $response = json_encode([
                    'id' => $id,
                    'unauthenticated' => true,
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR);
            } catch (AuthorizationException $e) {
                $response = json_encode([
                    'id' => $id,
                    'unauthorized' => true,
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR);
            } catch (ValidationException $e) {
                $response = json_encode([
                    'id' => $id,
                    'validation_errors' => $e->errors(),
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR);
            } catch (RscRedirectException $e) {
                $response = json_encode([
                    'id' => $id,
                    'redirect' => $e->getLocation(),
                ], JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $response = json_encode(['id' => $id, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
            }

            $this->writeFrame($socket, $response);
        }
    }

    /**
     * The request, as the worker can rebuild it.
     *
     * headers() and cookies() run inside the JavaScript render, which has a
     * socket rather than a request — so what it needs travels on the message.
     * Sent for every render, because whether a page reads a header is the
     * page's business and not something this can know in advance.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    private function requestEnvelope(): array
    {
        $request = request();

        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        // The decrypted jar, not the raw header. EncryptCookies has already run
        // by the time a route renders, so $request->cookies holds plaintext
        // while the Cookie header still holds what the browser sent — which is
        // ciphertext for every cookie Laravel wrote. Forwarding the raw header
        // meant cookies()->get() returned an encrypted blob for any app cookie,
        // and the engine could not read what it had itself set on the previous
        // response.
        $cookies = $request->cookies->all();

        if ($cookies !== []) {
            $headers['cookie'] = self::cookieHeader($cookies);
        } else {
            unset($headers['cookie']);
        }

        return ['url' => $request->fullUrl(), 'headers' => $headers];
    }

    /**
     * A Cookie header the engine can parse, from Laravel's decrypted values.
     *
     * @param  array<string, mixed>  $cookies
     */
    private static function cookieHeader(array $cookies): string
    {
        $pairs = [];

        foreach ($cookies as $name => $value) {
            // An array-valued cookie has no single wire form; the engine asks
            // for cookies by name and gets strings, so anything else is skipped
            // rather than serialized into something it would misread.
            if (! is_scalar($value)) {
                continue;
            }

            $pairs[] = $name.'='.rawurlencode((string) $value);
        }

        return implode('; ', $pairs);
    }

    /**
     * What the action just streamed asked to put on the response.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function actionHeaders(): array
    {
        return $this->actionHeaders;
    }

    /**
     * Response headers the worker put on a frame, if any.
     *
     * A list of pairs rather than a map, because Set-Cookie legitimately
     * repeats — several cookies are several headers, and a map would keep the
     * last one.
     *
     * @param  array<string, mixed>  $frame
     * @return list<array{0: string, 1: string}>
     */
    private function frameHeaders(array $frame): array
    {
        $headers = $frame['headers'] ?? null;

        if (! is_array($headers)) {
            return [];
        }

        return array_values(array_filter(
            $headers,
            fn ($pair): bool => is_array($pair) && count($pair) === 2,
        ));
    }

    /**
     * Where the render says to go instead.
     *
     * Told apart from a failure because the two become different responses: a
     * redirect is a Location header and a 3xx, an error is a 500. On the
     * start frame this arrives before anything has been written, which is what
     * leaves a status line available to use.
     *
     * @param  array<string, mixed>  $frame
     *
     * @throws RscRedirectException
     */
    private function throwIfRedirect(array $frame): void
    {
        if (! isset($frame['redirect'])) {
            return;
        }

        throw new RscRedirectException(
            $frame['redirect'],
            (int) ($frame['redirectStatus'] ?? 307),
            $this->frameHeaders($frame),
        );
    }

    /**
     * @param  array<string, mixed>  $frame
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    private function throwIfAuthError(array $frame): void
    {
        if (isset($frame['unauthenticated'])) {
            throw new AuthenticationException($frame['error'] ?? 'Unauthenticated.');
        }

        if (isset($frame['unauthorized'])) {
            throw new AuthorizationException($frame['error'] ?? 'This action is unauthorized.');
        }
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function throwIfValidationError(array $frame): void
    {
        if (! isset($frame['validation_errors'])) {
            return;
        }

        throw ValidationException::withMessages($frame['validation_errors']);
    }

    /**
     * Write one length-prefixed frame.
     *
     * The payload is bytes, not text — strlen counts bytes and sockets carry
     * them — which is what lets an upload body travel as its own frame with no
     * encoding.
     */
    private function writeFrame(Socket $socket, string $payload): void
    {
        $frame = pack('N', strlen($payload)).$payload;
        $frameLen = strlen($frame);
        $written = socket_write($socket, $frame, $frameLen);

        if ($written === false || $written === 0) {
            throw new RuntimeException('Failed to write to socket');
        }

        if ($written < $frameLen) {
            $offset = $written;
            $remaining = $frameLen - $written;

            while ($remaining > 0) {
                $written = socket_write($socket, substr($frame, $offset), $remaining);

                if ($written === false || $written === 0) {
                    throw new RuntimeException('Failed to write to socket');
                }

                $offset += $written;
                $remaining -= $written;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readFrame(Socket $socket): array
    {
        $length = unpack('N', $this->readExactly($socket, 4))[1];

        if ($length <= 0 || $length > $this->maxFrameSize) {
            throw new RuntimeException('Invalid frame length from socket');
        }

        $data = json_decode($this->readExactly($socket, $length), true);

        if (! is_array($data)) {
            throw new RuntimeException('Invalid JSON response from socket');
        }

        return $data;
    }

    /**
     * Read exactly $length bytes, looping until satisfied.
     *
     * A single socket_read on a stream socket may return fewer bytes than
     * requested — including a partial 4-byte header when the worker's write
     * lands across packet boundaries under load. Treating a short read as
     * fatal caused sporadic "Failed to read from socket" errors on large
     * streamed payloads, so both the header and body are read through here.
     */
    private function readExactly(Socket $socket, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = socket_read($socket, $length - strlen($buffer), PHP_BINARY_READ);

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Failed to read from socket');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @return array<string, mixed>
     */
    private function send(string $json): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt < $this->workerCount; $attempt++) {
            $index = $this->currentWorker++ % $this->workerCount;
            $socket = $this->checkout($index);

            try {
                $this->writeFrame($socket, $json);
                $response = $this->readFrame($socket);
                $this->release($index, $socket);

                return $response;
            } catch (RuntimeException $e) {
                socket_close($socket);
                $lastException = $e;
            }
        }

        throw $lastException ?? new RuntimeException('All Bun workers are unavailable');
    }

    /**
     * Check out a socket from the pool for exclusive use.
     *
     * Pooled sockets the worker closed while they sat idle (restart, deploy,
     * crash) are detected and discarded here, so a stale connection surfaces
     * as a fresh reconnect rather than a 500 on the next request. Creates a
     * new connection when none are idle.
     */
    /**
     * Fall back to a worker that is actually listening.
     *
     * The worker count comes from CPU/memory detection, which can read
     * differently in PHP-FPM than in the CLI that started the workers. When
     * this side guesses high, connecting to the missing index would fail the
     * request outright; using a worker that exists costs some spread across the
     * pool instead, which is the better failure.
     */
    private function availableWorker(int $index): int
    {
        if ($this->transport === 'tcp' || file_exists($this->socketPaths[$index] ?? '')) {
            return $index;
        }

        foreach (array_keys($this->socketPaths) as $candidate) {
            if (file_exists($this->socketPaths[$candidate])) {
                return $candidate;
            }
        }

        return $index;
    }

    private function checkout(int $index): Socket
    {
        $index = $this->availableWorker($index);

        while (! empty($this->pool[$index])) {
            $socket = array_pop($this->pool[$index]);

            if (! $this->socketHasPendingData($socket)) {
                return $socket;
            }

            @socket_close($socket);
        }

        return $this->createSocket($index);
    }

    /**
     * Return a socket to the pool after use.
     */
    private function release(int $index, Socket $socket): void
    {
        $this->pool[$index][] = $socket;
    }

    /**
     * Check out a callback socket from the persistent pool.
     * Registers with a callbackId so the Bun worker can match it.
     */
    private function checkoutCallback(int $index, string $callbackId): Socket
    {
        while (! empty($this->cbPool[$index])) {
            $socket = array_pop($this->cbPool[$index]);

            // Skip sockets the worker closed while idle.
            if ($this->socketHasPendingData($socket)) {
                @socket_close($socket);

                continue;
            }

            try {
                // Re-register with the new callbackId.
                $this->writeFrame($socket, json_encode([
                    'type' => 'register',
                    'id' => $callbackId,
                ], JSON_THROW_ON_ERROR));

                return $socket;
            } catch (RuntimeException) {
                // Socket died between the liveness check and the register
                // write; discard it and try the next pooled connection.
                @socket_close($socket);
            }
        }

        return $this->createCallbackSocket($index, $callbackId);
    }

    private function releaseCallback(int $index, Socket $socket, bool $clean = true): void
    {
        // A dirty callback socket must never be pooled. If a partial frame was
        // buffered, an unanswered callback request is still on the wire, or the
        // render ended via an exception, the next request to check this socket
        // out would resume mid-frame or execute a stale callback under the
        // wrong session/auth context. Discard it and let the pool recreate a
        // fresh connection.
        if (! $clean || $this->socketHasPendingData($socket)) {
            @socket_close($socket);

            return;
        }

        $this->cbPool[$index][] = $socket;
    }

    /**
     * Non-blocking check for unread bytes (or EOF) on a socket.
     *
     * A healthy idle pooled socket has nothing to read. If select reports it
     * readable, the worker either closed the connection (EOF) or left an
     * unconsumed frame on the wire — in both cases the socket is unsafe to
     * reuse and must be discarded rather than pooled.
     */
    private function socketHasPendingData(Socket $socket): bool
    {
        $read = [$socket];
        $write = [];
        $except = [];

        return @socket_select($read, $write, $except, 0) > 0;
    }

    /**
     * Idle timeout (seconds) for the streaming select loops. Bounds how long a
     * hung render may hold an FPM worker before it is aborted.
     */
    private function streamIdleTimeout(): int
    {
        return max(1, (int) config('rsc.stream_timeout', 30));
    }

    /**
     * Read the opening frame of a stream (stream-start / html-start) while
     * concurrently servicing php() callbacks on the callback socket.
     *
     * The Bun worker resolves page metadata (generateMetadata) before it emits
     * the opening frame, and that resolution may itself issue php() callbacks.
     * A bare readFrame() here would never answer those callbacks, so the worker
     * could never produce the opening frame — both sides deadlock until the
     * socket timeout fires. Servicing callbacks during the wait preserves the
     * "opening frame yielded first" guarantee without the deadlock.
     *
     * @return array<string, mixed>
     */
    private function readStartFrame(Socket $mainSocket, ?Socket $callbackSocket, CallableRegistry $registry, string &$callbackBuffer): array
    {
        if ($callbackSocket === null) {
            return $this->readFrame($mainSocket);
        }

        $timeout = $this->streamIdleTimeout();

        while (true) {
            $read = [$mainSocket, $callbackSocket];
            $write = [];
            $except = [];

            $changed = socket_select($read, $write, $except, $timeout);

            if ($changed === false) {
                throw new RuntimeException('socket_select() failed: '.socket_strerror(socket_last_error()));
            }

            if ($changed === 0) {
                throw new RuntimeException("Bun render exceeded {$timeout}s idle timeout waiting for stream start");
            }

            // Prioritise the opening frame. If it is ready the worker has
            // already resolved metadata, so any pending callback is for the
            // streaming body and the main loop will drain it after we return.
            if (in_array($mainSocket, $read, true)) {
                return $this->readFrame($mainSocket);
            }

            if (in_array($callbackSocket, $read, true)) {
                $this->handleCallbackData($callbackSocket, $callbackBuffer, $registry);
            }
        }
    }

    private function createCallbackSocket(int $index, string $callbackId): Socket
    {
        if ($this->transport === 'tcp') {
            $port = $this->cbPorts[$index];
            $socket = $this->connectWithRetry(
                fn () => $this->openConnection(true, $this->host, $port, "Bun callback listener {$this->host}:{$port}"),
            );
        } else {
            $path = $this->cbSocketPaths[$index];

            if (! file_exists($path)) {
                throw new RuntimeException(
                    "Bun callback socket not found at {$path}. Ensure rsc:serve is running."
                );
            }

            $socket = $this->openConnection(false, $path, null, "Bun callback socket at {$path}");
        }

        // Register with the Bun callback server
        $this->writeFrame($socket, json_encode([
            'type' => 'register',
            'id' => $callbackId,
        ], JSON_THROW_ON_ERROR));

        return $socket;
    }

    private function nextCallbackId(): string
    {
        return 'cb_'.bin2hex(random_bytes(4)).'_'.(++$this->cbIdCounter);
    }

    private function createSocket(int $index): Socket
    {
        if ($this->transport === 'tcp') {
            $port = $this->mainPorts[$index];

            return $this->connectWithRetry(
                fn () => $this->openConnection(true, $this->host, $port, "Bun worker {$this->host}:{$port}"),
            );
        }

        $path = $this->socketPaths[$index];
        $this->waitForSocketFile($path);

        return $this->openConnection(false, $path, null, "Bun socket at {$path}");
    }

    /**
     * Wait briefly for a Unix socket file to appear — the Bun worker may still
     * be starting up (on boot or after an HMR rebuild).
     */
    private function waitForSocketFile(string $path): void
    {
        $waited = 0.0;

        while (! file_exists($path) && $waited < 3.0) {
            usleep(100_000); // 100ms
            $waited += 0.1;
        }

        if (! file_exists($path)) {
            throw new RuntimeException(
                "Bun socket not found at {$path}. Run: php artisan rsc:serve"
            );
        }
    }

    /**
     * Retry a connection attempt for up to 3 seconds. For TCP there is no
     * socket file to wait on, so a worker that is still binding its port
     * returns connection-refused; we retry until it is listening.
     */
    private function connectWithRetry(\Closure $connect): Socket
    {
        $deadline = microtime(true) + 3.0;

        while (true) {
            try {
                return $connect();
            } catch (RuntimeException $e) {
                if (microtime(true) >= $deadline) {
                    throw $e;
                }

                usleep(100_000); // 100ms
            }
        }
    }

    /**
     * Open a blocking, timeout-guarded stream connection to the worker over
     * either a Unix socket path or a TCP host/port. Uses a non-blocking connect
     * with a 3-second select so it never hangs when the worker isn't ready.
     */
    private function openConnection(bool $tcp, string $address, ?int $port, string $label): Socket
    {
        $socket = socket_create($tcp ? AF_INET : AF_UNIX, SOCK_STREAM, $tcp ? SOL_TCP : 0);

        if ($socket === false) {
            throw new RuntimeException('Failed to create socket: '.socket_strerror(socket_last_error()));
        }

        socket_set_nonblock($socket);
        $connected = $tcp
            ? @socket_connect($socket, $address, $port)
            : @socket_connect($socket, $address);

        if (! $connected) {
            $error = socket_last_error($socket);

            // EINPROGRESS/EALREADY: connection is in progress — wait for writable.
            if ($error === SOCKET_EINPROGRESS || $error === SOCKET_EALREADY || $error === 0) {
                $write = [$socket];
                $read = null;
                $except = null;

                $ready = socket_select($read, $write, $except, 3);

                if ($ready === false || $ready === 0) {
                    socket_close($socket);

                    throw new RuntimeException("{$label} not ready (connection timed out).");
                }

                // A writable non-blocking socket may still have failed to
                // connect (e.g. TCP connection refused) — confirm via SO_ERROR.
                $soError = socket_get_option($socket, SOL_SOCKET, SO_ERROR);

                if ($soError !== 0) {
                    socket_close($socket);

                    throw new RuntimeException("{$label} connection failed: ".socket_strerror($soError));
                }
            } else {
                $message = socket_strerror($error);
                socket_close($socket);

                throw new RuntimeException("Failed to connect to {$label}: {$message}");
            }
        }

        socket_set_block($socket);

        $timeout = ['sec' => 10, 'usec' => 0];
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, $timeout);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, $timeout);

        if ($tcp) {
            // Low-latency small frames — disable Nagle's algorithm.
            @socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        }

        return $socket;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
