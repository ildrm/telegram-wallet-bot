<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if ($app['config']['database.default'] === 'mysql'
            && $app['config']['database.connections.mysql.database'] !== 'wallet_test') {
            throw new \RuntimeException('Destructive MySQL tests require the isolated wallet_test database.');
        }

        return $app;
    }
}
