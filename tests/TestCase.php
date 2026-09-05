<?php

namespace Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RscKit\RscKitServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            RscKitServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('rsc.socket_path', '/tmp/bun-bridge-test.sock');
        $app['config']->set('rsc.enabled', true);
    }
}
