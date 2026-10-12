<?php

namespace Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use PDO;

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

    protected function setUp(): void
    {
        parent::setUp();

        // With persistent connections (below), every PDO object a worker
        // creates for PostgreSQL shares ONE server connection, and PHP rolls
        // that connection's transaction back whenever any of those objects is
        // freed while a transaction is open. A test that does not use
        // RefreshDatabase never disconnects, so its PDO object lingered until
        // the cycle collector freed it — in the middle of a later test, taking
        // that test's transaction with it. Disconnecting here frees every PDO
        // object while the application is torn down, when no transaction is
        // left open. RefreshDatabase's own rollback is registered first, so it
        // has already run by then.
        $this->beforeApplicationDestroyed(function (): void {
            foreach ($this->app['db']->getConnections() as $connection) {
                $connection->disconnect();
            }
        });
    }

    public function createApplication()
    {
        // Mirrors the parent, because the hook has to be registered between
        // building the application and bootstrapping it, and the parent has no
        // seam for that.
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }

        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(fn () => $this->markRoutesCached($app));
        }

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            static::keepPostgresConnectionsAcrossTests($app['config']);

            // Background translation drafts live in Redis on a deployed target;
            // tests keep them in the application's own array store, so no test
            // needs a Redis server and every test starts with none.
            $app['config']->set('translation.bulk.cache_store', 'array');

            // Signing in and asking for a password reset are timeboxed: Laravel
            // pads each to 200 ms, so how long one takes cannot tell an attacker
            // whether the account exists. Tests measure no timing, and the
            // padding was real sleep — 200 ms in every test that signs in.
            $app['config']->set('auth.timebox_duration', 0);

            foreach (static::$bootConfiguration as $key => $value) {
                $app['config']->set($key, $value);
            }
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Every test that touches the database reconnects to it: RefreshDatabase
     * rolls the test's transaction back and then disconnects, and the next
     * test's fresh application opens a new connection. Against PostgreSQL that
     * means forking a server backend, a SCRAM password exchange and a cold
     * catalogue cache for every single test — enough that CI's PostgreSQL job
     * spent about half as long again on Unit and Feature as the SQLite and
     * MariaDB jobs did on the same tests.
     *
     * A persistent PDO connection survives the disconnect, so each test worker
     * keeps one backend for its whole run. Nothing carries over between tests:
     * the transaction is rolled back before the disconnect, and the application
     * sets no session state (SET, LISTEN, advisory locks, temporary tables)
     * that a later test could see.
     */
    private static function keepPostgresConnectionsAcrossTests(Repository $config): void
    {
        $config->set(
            'database.connections.pgsql.options',
            [PDO::ATTR_PERSISTENT => true] + $config->get('database.connections.pgsql.options', []),
        );
    }
}
