<?php

namespace App\Providers;

use App\Support\Observability\NightwatchPrivacy;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Facades\Nightwatch;
use Sentry\State\HubInterface;
use Sentry\State\Scope;

/**
 * The one place application-wide observability context is configured.
 *
 * Everything here is stable, low-cardinality metadata that belongs on every
 * event; controllers, jobs and services never touch a vendor's scope
 * themselves.
 *
 * Two products are configured side by side for the Phase 6B evaluation, each
 * through its own official API and with no shared abstraction between them.
 * They are deliberately not symmetrical, because their native event shapes are
 * not: Sentry has native `environment` and `release` fields (populated from
 * config/sentry.php), so only the two facts it has no field for are tagged,
 * while Nightwatch has a native `deploy` field (config/nightwatch.php) and
 * reads everything else from Laravel's Context.
 */
final class ObservabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->shareDeploymentContext();
        $this->configureSentry();
        $this->configureNightwatch();
    }

    /**
     * The deployment facts every log record and every Nightwatch event carries.
     *
     * Laravel's Context is the framework's own correlation mechanism and the
     * one Nightwatch reads natively (it serializes Context::all() onto each
     * request, command and job record), so there is no RateGuru-specific
     * carrier for these — and, because Context is dehydrated into every queued
     * job payload and rehydrated by the worker, they cross the queue boundary
     * without anything here arranging it.
     *
     * The values come from config/deployment.php, which is the single
     * authoritative reader of the artifact's release.json. Nothing here reads
     * Git, and nothing re-derives a release: a deployed release is an
     * immutable directory with no .git in it.
     *
     * Blank values are omitted rather than sent as empty strings — locally and
     * in CI there is no release, and "absent" is the honest answer.
     */
    private function shareDeploymentContext(): void
    {
        $context = array_filter([
            'app' => 'RateGuru',
            'environment' => $this->app->environment(),
            'deployment_target' => config('deployment.target'),
            'release' => config('deployment.release'),
            'commit' => config('deployment.commit'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        Context::add($context);
    }

    private function configureSentry(): void
    {
        // Sentry is optional. Without the package — or with no DSN configured,
        // which is the normal local and CI state — there is simply nothing to
        // configure and the application boots and serves exactly as before.
        // Deciding whether anything is actually transmitted is the SDK's job,
        // not ours: describing the scope costs nothing when nothing is sent.
        if (! $this->app->bound(HubInterface::class)) {
            return;
        }

        $this->configureCommonScope();
        $this->attachUserIdOnAuthentication();
    }

    /**
     * Narrows what Nightwatch may transmit to RateGuru's privacy baseline.
     *
     * Registered whether or not Nightwatch is enabled, for the same reason the
     * Sentry scope is: the package binds its Core in `register()` regardless,
     * describing a boundary costs nothing when nothing is sent, and a
     * configuration that only exists on the enabled path is a configuration
     * no test can hold to account.
     *
     * Every callback lives in NightwatchPrivacy, which is where the reasoning
     * for each one is written down.
     */
    private function configureNightwatch(): void
    {
        if (! $this->app->bound(Nightwatch::getFacadeAccessor())) {
            return;
        }

        $privacy = $this->app->make(NightwatchPrivacy::class);

        Nightwatch::user($privacy->userDetails(...));
        Nightwatch::redactRequests($privacy->redactRequest(...));
        Nightwatch::redactOutgoingRequests($privacy->redactOutgoingRequest(...));
        Nightwatch::redactCommands($privacy->redactCommand(...));
        Nightwatch::redactExceptions($privacy->redactException(...));
        Nightwatch::rejectCacheEvents($privacy->rejectCacheEvent(...));

        // Queries are deliberately not redacted. Laravel hands Nightwatch
        // QueryExecuted::$sql, which is the parameterized statement — the
        // bindings, where every value worth protecting lives, are not part of
        // the record at all. A redaction callback here would be a comforting
        // no-op that hid the fact nobody had checked. The sentinel test in
        // tests/Feature/Observability/NightwatchIngestPrivacyTest.php checks,
        // by reading the records the package was about to transmit.
    }

    private function configureCommonScope(): void
    {
        $tags = array_filter([
            // Which brand/target inside the environment class is serving this
            // runtime. Data, never a branch: no code anywhere compares it
            // against a specific target ID.
            'deployment_target' => config('deployment.target'),

            // The Git commit the deployed release was built from, taken from
            // the artifact's release.json rather than from Git at runtime.
            'commit' => config('deployment.commit'),

            // Always present: it costs nothing and makes a shared Sentry
            // organization legible if a second project ever joins it.
            'app' => 'RateGuru',
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        $this->app->make(HubInterface::class)->configureScope(
            static function (Scope $scope) use ($tags): void {
                /** @var array<string, string> $tags */
                foreach ($tags as $key => $value) {
                    $scope->setTag($key, $value);
                }
            }
        );
    }

    /**
     * The maximum identity RateGuru ever attaches to a Sentry event.
     *
     * With `send_default_pii` false the SDK does not subscribe to Laravel's
     * auth events at all, so nothing about the user reaches Sentry by default.
     * That is the right default — but the internal database ID alone is both
     * safe and the difference between "some user hit this" and a reproducible
     * report, so it is added back explicitly. Email, username, display name and
     * IP address are never attached.
     */
    private function attachUserIdOnAuthentication(): void
    {
        $this->app->make(Dispatcher::class)->listen(
            Authenticated::class,
            function (Authenticated $event): void {
                $id = $event->user->getAuthIdentifier();

                if (! is_int($id) && ! is_string($id)) {
                    return;
                }

                $this->app->make(HubInterface::class)->configureScope(
                    static function (Scope $scope) use ($id): void {
                        $scope->setUser(['id' => (string) $id]);
                    }
                );
            }
        );
    }
}
