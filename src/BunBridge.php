<?php

namespace LaraBun;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use LaraBun\Rsc\CallableRegistry;
use LaraBun\Rsc\RscRedirectException;
use RuntimeException;
use Socket;

class BunBridge
{
    /** @var string[] */
    private array $socketPaths;

    /**
     * Pool of available (idle) sockets per worker index.
     *
     * @var array<int, Socket[]>
     */
    private array $pool = [];

    private int $workerCount;

    private int $currentWorker;

    private int $maxFrameSize;

    public function __construct()
    {
        if (! extension_loaded('sockets')) {
            throw new RuntimeException('The sockets extension is required. Enable it in php.ini.');
        }

        $basePath = config('bun.socket_path', '/tmp/bun-bridge.sock');
        $this->workerCount = max(1, (int) config('bun.workers', 1));
        $this->currentWorker = $this->workerCount > 1 ? random_int(0, $this->workerCount - 1) : 0;
        $this->maxFrameSize = self::parseSize(config('bun.rsc.body_size_limit', '1mb'));

        if ($this->workerCount === 1) {
            $this->socketPaths = [$basePath];
        } else {
            $base = preg_replace('/\.sock$/', '', $basePath);

            for ($i = 0; $i < $this->workerCount; $i++) {
                $this->socketPaths[] = "{$base}-{$i}.sock";
            }
        }
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
     * @param  array<string, mixed>  $page
     * @return array{head: array<int, string>, body: string}
     */
    public function ssr(array $page): array
    {
        $response = $this->send(json_encode([
            'type' => 'ssr',
            'page' => $page,
        ], JSON_THROW_ON_ERROR));

        if (isset($response['error'])) {
            throw new RuntimeException("Bun SSR error: {$response['error']}");
        }

        if (! isset($response['result']) || ! is_array($response['result'])) {
            throw new RuntimeException('Invalid SSR response from Bun');
        }

        return $response['result'];
    }

    /**
     * Render a PPR shell — page with mock php() so async components suspend
     * and Suspense shows fallback content. Returns just the shell HTML.
     *
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     * @return array{shellHtml: string, clientChunks: string[], timedOut: bool}
     */
    public function rscPprShell(string $component, array $props = [], array $layouts = []): array
    {
        $response = $this->send(json_encode([
            'type' => 'rsc-ppr-shell',
            'component' => $component,
            'props' => $props,
            'layouts' => $layouts,
        ], JSON_THROW_ON_ERROR));

        if (isset($response['error'])) {
            throw new RuntimeException("Bun PPR shell error: {$response['error']}");
        }

        return $response['result'];
    }

    /**
     * Render RSC with inline callback handling on the main socket.
     *
     * Callbacks from the Bun worker (php() calls) arrive as frames on the
     * same socket used for the render request. This eliminates the per-request
     * callback socket that was prone to race conditions under Octane.
     *
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     * @return array{body: string, rscPayload: string, clientChunks: string[], usedDynamicApis?: bool}
     */
    public function rsc(string $component, array $props = [], array $layouts = [], bool $isPrerender = false): array
    {
        $index = $this->currentWorker++ % $this->workerCount;
        $mainSocket = $this->checkout($index);
        $registry = app(CallableRegistry::class);

        try {
            $message = [
                'type' => 'rsc',
                'component' => $component,
                'props' => $props,
                'layouts' => $layouts,
            ];

            if ($isPrerender) {
                $message['isPrerender'] = true;
            }

            $this->writeFrame($mainSocket, json_encode($message, JSON_THROW_ON_ERROR));

            // Read frames in a loop — handle callbacks inline, return on final response
            while (true) {
                $frame = $this->readFrame($mainSocket);

                if (($frame['type'] ?? '') === 'callback') {
                    $this->handleCallback($mainSocket, $frame, $registry);

                    continue;
                }

                $this->throwIfAuthError($frame);

                if (isset($frame['error'])) {
                    throw new RuntimeException("Bun RSC error: {$frame['error']}");
                }

                if (isset($frame['result']) && is_array($frame['result'])) {
                    $this->release($index, $mainSocket);

                    return $frame['result'];
                }

                throw new RuntimeException('Invalid RSC response from Bun');
            }
        } catch (\Throwable $e) {
            @socket_close($mainSocket);

            throw $e;
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
    public function rscStream(string $component, array $props = [], array $layouts = []): \Generator
    {
        $registry = app(CallableRegistry::class);
        $index = $this->currentWorker++ % $this->workerCount;
        $mainSocket = $this->checkout($index);

        try {
            $this->writeFrame($mainSocket, json_encode([
                'type' => 'rsc-stream',
                'component' => $component,
                'props' => $props,
                'layouts' => $layouts,
            ], JSON_THROW_ON_ERROR));

            while (true) {
                $frame = $this->readFrame($mainSocket);

                if (($frame['type'] ?? '') === 'callback') {
                    $this->handleCallback($mainSocket, $frame, $registry);

                    continue;
                }

                $this->throwIfAuthError($frame);

                if (isset($frame['error'])) {
                    throw new RuntimeException("Bun RSC stream error: {$frame['error']}");
                }

                $type = $frame['type'] ?? '';

                if ($type === 'stream-start') {
                    yield [
                        'clientChunks' => $frame['clientChunks'] ?? [],
                        'metadata' => $frame['metadata'] ?? null,
                    ];

                    continue;
                }

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
        } finally {
            if ($mainSocket !== null) {
                @socket_close($mainSocket);
            }
        }
    }

    /**
     * Stream HTML for initial page loads with Suspense support.
     *
     * Callbacks from php() arrive as frames on the same main socket,
     * interleaved with HTML stream frames.
     *
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     * @return \Generator<int, array{clientChunks?: string[], metadata?: mixed, rscPayload?: string}|string, void, void>
     */
    public function rscHtmlStream(string $component, array $props = [], array $layouts = []): \Generator
    {
        $registry = app(CallableRegistry::class);
        $index = $this->currentWorker++ % $this->workerCount;
        $mainSocket = $this->checkout($index);

        try {
            $this->writeFrame($mainSocket, json_encode([
                'type' => 'rsc-html-stream',
                'component' => $component,
                'props' => $props,
                'layouts' => $layouts,
            ], JSON_THROW_ON_ERROR));

            while (true) {
                $frame = $this->readFrame($mainSocket);

                if (($frame['type'] ?? '') === 'callback') {
                    $this->handleCallback($mainSocket, $frame, $registry);

                    continue;
                }

                $this->throwIfAuthError($frame);

                if (isset($frame['error'])) {
                    throw new RuntimeException("Bun RSC HTML stream error: {$frame['error']}");
                }

                $type = $frame['type'] ?? '';

                if ($type === 'html-start') {
                    yield ['clientChunks' => $frame['clientChunks'] ?? [], 'metadata' => $frame['metadata'] ?? null];

                    continue;
                }

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
        } finally {
            if ($mainSocket !== null) {
                @socket_close($mainSocket);
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
    public function rscAction(string $actionId, string $body, string $contentType = 'text/plain'): \Generator
    {
        $registry = app(CallableRegistry::class);
        $index = $this->currentWorker++ % $this->workerCount;
        $mainSocket = $this->checkout($index);

        try {
            $this->writeFrame($mainSocket, json_encode([
                'type' => 'rsc-action',
                'actionId' => $actionId,
                'body' => $body,
                'contentType' => $contentType,
            ], JSON_THROW_ON_ERROR));

            while (true) {
                $frame = $this->readFrame($mainSocket);

                if (($frame['type'] ?? '') === 'callback') {
                    $this->handleCallback($mainSocket, $frame, $registry);

                    continue;
                }

                if (isset($frame['redirect'])) {
                    throw new RscRedirectException($frame['redirect']);
                }

                $this->throwIfAuthError($frame);
                $this->throwIfValidationError($frame);

                if (isset($frame['error'])) {
                    throw new RuntimeException("Bun RSC action error: {$frame['error']}");
                }

                $type = $frame['type'] ?? '';

                if ($type === 'action-start') {
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
        } finally {
            if ($mainSocket !== null) {
                @socket_close($mainSocket);
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

        foreach ($this->socketPaths as $i => $path) {
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

    /**
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     * @return array{body: string, rscPayload: string, clientChunks: string[], usedDynamicApis?: bool}
     */
    /**
     * Handle a callback frame from Bun on the main socket.
     *
     * Bun sends callback frames when php() is called during rendering.
     * We execute the callable and write the response back on the same socket.
     *
     * @param  array<string, mixed>  $frame
     */
    private function handleCallback(Socket $socket, array $frame, CallableRegistry $registry): void
    {
        $id = $frame['id'] ?? '';
        $function = $frame['function'] ?? '';
        $args = $frame['args'] ?? [];

        try {
            $result = $registry->execute($function, $args);
            $response = json_encode([
                'type' => 'callback-response',
                'id' => $id,
                'result' => $result,
            ], JSON_THROW_ON_ERROR);
        } catch (AuthenticationException $e) {
            $response = json_encode([
                'type' => 'callback-response',
                'id' => $id,
                'unauthenticated' => true,
                'error' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        } catch (AuthorizationException $e) {
            $response = json_encode([
                'type' => 'callback-response',
                'id' => $id,
                'unauthorized' => true,
                'error' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        } catch (ValidationException $e) {
            $response = json_encode([
                'type' => 'callback-response',
                'id' => $id,
                'validation_errors' => $e->errors(),
                'error' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        } catch (RscRedirectException $e) {
            $response = json_encode([
                'type' => 'callback-response',
                'id' => $id,
                'redirect' => $e->getLocation(),
            ], JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $response = json_encode([
                'type' => 'callback-response',
                'id' => $id,
                'error' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }

        $this->writeFrame($socket, $response);
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

    private function writeFrame(Socket $socket, string $json): void
    {
        $frame = pack('N', strlen($json)).$json;
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
        $header = socket_read($socket, 4, PHP_BINARY_READ);

        if ($header === false || strlen($header) < 4) {
            throw new RuntimeException('Failed to read from socket');
        }

        $length = unpack('N', $header)[1];

        if ($length <= 0 || $length > $this->maxFrameSize) {
            throw new RuntimeException('Invalid frame length from socket');
        }

        $body = socket_read($socket, $length, PHP_BINARY_READ);

        if ($body === false || $body === '') {
            throw new RuntimeException('Failed to read from socket');
        }

        while (strlen($body) < $length) {
            $chunk = socket_read($socket, $length - strlen($body), PHP_BINARY_READ);

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Failed to read from socket');
            }

            $body .= $chunk;
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new RuntimeException('Invalid JSON response from socket');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function send(string $json): array
    {
        $lastException = null;
        $maxAttempts = max($this->workerCount, 3);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $index = $this->currentWorker++ % $this->workerCount;
            $socket = $this->checkout($index);

            try {
                $this->writeFrame($socket, $json);
                $response = $this->readFrame($socket);
                $this->release($index, $socket);

                return $response;
            } catch (RuntimeException $e) {
                @socket_close($socket);

                // Clear the pool for this worker — sockets may be stale
                if (! empty($this->pool[$index])) {
                    foreach ($this->pool[$index] as $stale) {
                        @socket_close($stale);
                    }
                    $this->pool[$index] = [];
                }

                $lastException = $e;

                if ($attempt < $maxAttempts - 1) {
                    usleep(50_000 * ($attempt + 1)); // 50ms, 100ms, 150ms
                }
            }
        }

        throw $lastException ?? new RuntimeException('All Bun workers are unavailable');
    }

    /**
     * Check out a socket from the pool for exclusive use.
     * Creates a new connection if no idle sockets are available.
     */
    private function checkout(int $index): Socket
    {
        // Try pooled sockets, validating they're still alive
        while (! empty($this->pool[$index])) {
            $socket = array_pop($this->pool[$index]);

            if ($this->isSocketAlive($socket)) {
                return $socket;
            }

            @socket_close($socket);
        }

        return $this->connectWithRetry($index);
    }

    /**
     * Return a socket to the pool after use.
     */
    private function release(int $index, Socket $socket): void
    {
        if ($this->isSocketAlive($socket)) {
            $this->pool[$index][] = $socket;
        } else {
            @socket_close($socket);
        }
    }

    /**
     * Check if a socket is still connected and usable.
     */
    private function isSocketAlive(Socket $socket): bool
    {
        // Attempt a zero-length read — if the socket is dead, it returns false or 0
        socket_set_nonblock($socket);
        $buf = '';
        $result = @socket_recv($socket, $buf, 1, MSG_PEEK | MSG_DONTWAIT);
        socket_set_block($socket);

        // false = error (dead), 0 = peer closed, >0 or null = alive (no data or data waiting)
        return $result !== false && $result !== 0;
    }

    /**
     * Connect to the Bun worker with retry logic.
     */
    private function connectWithRetry(int $index, int $maxRetries = 3): Socket
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->createSocket($index);
            } catch (RuntimeException $e) {
                $lastException = $e;

                if ($attempt < $maxRetries) {
                    usleep(100_000 * $attempt); // 100ms, 200ms, 300ms
                }
            }
        }

        throw $lastException;
    }

    private function createSocket(int $index): Socket
    {
        $path = $this->socketPaths[$index];

        if (! file_exists($path)) {
            throw new RuntimeException(
                "Bun socket not found at {$path}. Run: php artisan bun:serve"
            );
        }

        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);

        if ($socket === false) {
            throw new RuntimeException(
                'Failed to create socket: '.socket_strerror(socket_last_error())
            );
        }

        // Use non-blocking connect with a 3-second timeout to avoid hanging
        // when the socket file exists but the worker hasn't started listening yet.
        socket_set_nonblock($socket);
        $connected = @socket_connect($socket, $path);

        if (! $connected) {
            $error = socket_last_error($socket);

            // EINPROGRESS (115) or EALREADY (114) means connection is in progress
            if ($error === SOCKET_EINPROGRESS || $error === SOCKET_EALREADY || $error === 0) {
                $write = [$socket];
                $read = null;
                $except = null;

                $ready = socket_select($read, $write, $except, 3);

                if ($ready === false || $ready === 0) {
                    socket_close($socket);

                    throw new RuntimeException(
                        "Bun worker not ready (connection timed out). Is 'php artisan bun:serve' running?"
                    );
                }
            } else {
                $errorMsg = socket_strerror($error);
                socket_close($socket);

                throw new RuntimeException(
                    "Failed to connect to Bun socket: {$errorMsg}. Run: php artisan bun:serve"
                );
            }
        }

        socket_set_block($socket);

        $timeout = ['sec' => 10, 'usec' => 0];
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, $timeout);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, $timeout);

        return $socket;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
