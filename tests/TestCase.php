<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // The shared schema belongs to b5-db-2, including seeded dictionaries.
        $app->useDatabasePath(dirname(__DIR__, 2).'/b5-db-2/database');

        return $app;
    }
}
