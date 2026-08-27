<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Configuration to put in place *before* service providers boot.
     *
     * Some integrations decide what to register inside their provider's
     * `boot()` rather than at request time — the official Sentry providers
     * register their middleware and event subscribers only when a DSN is
     * already configured. For those, calling `config()` from inside a test is
     * far too late: the providers have long since decided to register nothing.
     *
     * Environment variables cannot be used for this either. Laravel reloads
     * `.env` on every application refresh and re-applies its values over
     * whatever the test put in `$_SERVER`, so any key `.env` defines — and
     * `.env.example`, which CI copies, defines all the `SENTRY_*` ones — is
     * silently reset on the next boot. Setting configuration directly at the
     * `LoadConfiguration` seam sidesteps that entirely and targets exactly what
     * the providers actually read.
     *
     * Set this in `beforeAll()` and clear it in `afterAll()`.
     *
     * @var array<string, mixed>
     */
    public static array $bootConfiguration = [];

    public function createApplication()
    {
        if (static::$bootConfiguration === []) {
            return parent::createApplication();
        }

        // Deliberately mirrors the parent for the opt-in path only, because the
        // hook has to be registered between building the application and
        // bootstrapping it, and there is no seam for that. The cached-config
        // and cached-routes branches the parent handles are not reachable here:
        // no test that sets boot configuration uses those traits.
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            foreach (static::$bootConfiguration as $key => $value) {
                $app['config']->set($key, $value);
            }
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
