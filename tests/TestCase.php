<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Environment variables to put in place *before* the application boots.
     *
     * Some integrations decide what to register in their service provider's
     * `boot()` — the official Sentry providers register their middleware and
     * event subscribers only when a DSN is already configured. Setting config
     * from inside a test is far too late for those: the providers have long
     * since decided to register nothing. A test that needs that decision to go
     * the other way sets this in `beforeAll()`, which PHPUnit runs before the
     * first `setUp()`, and clears it again in `afterAll()`.
     *
     * @var array<string, string>
     */
    public static array $bootEnvironment = [];

    protected function refreshApplication(): void
    {
        foreach (static::$bootEnvironment as $key => $value) {
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
        }

        parent::refreshApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (array_keys(static::$bootEnvironment) as $key) {
            unset($_SERVER[$key], $_ENV[$key]);
        }
    }
}
