<?php

/**
 * An upload body travels as bytes, on its own frame.
 *
 * Frames are length-prefixed bytes, so a body needs no encoding to survive
 * one. It was base64'd only because it used to ride inside the JSON frame,
 * which cannot carry bytes that are not valid UTF-8 — and that cost a third of
 * the size in transit, a copy on each side, and made the size limit measure
 * the encoded length rather than the file.
 */

use LaravelRsc\RuntimeBridge;

/** Write through a real socket pair and read back what arrived. */
function writtenFrame(string $payload): string
{
    $pair = [];
    socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);

    $bridge = new RuntimeBridge;
    $write = new ReflectionMethod($bridge, 'writeFrame');
    $write->setAccessible(true);
    $write->invoke($bridge, $pair[0], $payload);

    socket_close($pair[0]);

    $received = '';

    while (($chunk = socket_read($pair[1], 8192, PHP_BINARY_READ)) !== false && $chunk !== '') {
        $received .= $chunk;
    }

    socket_close($pair[1]);

    return $received;
}

test('a body survives the socket byte for byte', function () {
    // PNG magic, a NUL, and bytes that are not valid UTF-8 — json_encode
    // refuses this outright, which is why it used to be base64'd.
    $binary = "\x89PNG\r\n\x1a\n\x00\xff\xfe\x00binary";

    $frame = writtenFrame($binary);
    $length = unpack('N', substr($frame, 0, 4))[1];

    expect($length)->toBe(strlen($binary))
        ->and(substr($frame, 4))->toBe($binary);
});

test('the length prefix counts bytes, not characters', function () {
    // Multi-byte characters make these differ, and a prefix counting the wrong
    // one truncates every frame after it.
    $utf8 = 'héllo wörld';

    $frame = writtenFrame($utf8);

    expect(unpack('N', substr($frame, 0, 4))[1])->toBe(strlen($utf8))
        ->and(strlen($utf8))->toBeGreaterThan(mb_strlen($utf8));
});

test('json_encode could not have carried this, which is why base64 existed', function () {
    // The constraint the old transport was working around, stated outright.
    expect(fn () => json_encode(['body' => "\xff\xfe"], JSON_THROW_ON_ERROR))
        ->toThrow(JsonException::class);
});
