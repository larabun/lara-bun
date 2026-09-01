<?php

namespace LaravelRsc\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelRsc\RuntimeBridge;

/**
 * @method static mixed call(string $function, array $args = [])
 * @method static array<int, string> list()
 * @method static bool ping()
 * @method static void disconnect()
 *
 * @see RuntimeBridge
 */
class Rsc extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RuntimeBridge::class;
    }
}
