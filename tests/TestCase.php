<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Destructive tests require the isolated in-memory SQLite central database.');
        }
    }
}
