<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase wipes whatever database it is pointed at. Inside Docker the container's real
     * DB_* env vars can beat phpunit.xml, so refuse to run against anything but in-memory SQLite.
     */
    protected function beforeRefreshingDatabase()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException("Refusing to refresh the {$connection} database \"{$database}\": tests must use in-memory SQLite.");
        }
    }
}
