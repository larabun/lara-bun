<?php

namespace Tests;

use LaravelRsc\LaravelRscServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelRscServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('bun.socket_path', '/tmp/bun-bridge-test.sock');
        $app['config']->set('bun.rsc.enabled', true);
    }
}
