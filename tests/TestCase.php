<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests must never inherit the working/live database from a cached Laravel
     * config file or the parent Windows/XAMPP environment. Force the isolated
     * test DB before Laravel bootstraps, and point config/route cache lookups at
     * test-only (normally non-existent) paths so stale production caches cannot
     * override PHPUnit's environment.
     */
    public function createApplication()
    {
        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'carevma_test',
            'APP_CONFIG_CACHE' => 'bootstrap/cache/testing-config.php',
            'APP_ROUTES_CACHE' => 'bootstrap/cache/testing-routes.php',
        ];

        foreach ($environment as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        return parent::createApplication();
    }
}
