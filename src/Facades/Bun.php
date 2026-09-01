<?php

namespace LaravelRsc\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelRsc\BunBridge;

/**
 * @method static mixed call(string $function, array $args = [])
 * @method static array<int, string> list()
 * @method static bool ping()
 * @method static void disconnect()
 *
 * @see BunBridge
 */
class Bun extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BunBridge::class;
    }
}
