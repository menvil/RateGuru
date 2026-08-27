<?php

namespace App\Providers;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Sentry\State\HubInterface;
use Sentry\State\Scope;

/**
 * The one place application-wide Sentry context is configured.
 *
 * Everything here is stable, low-cardinality metadata that belongs on every
 * event; controllers, jobs and services never touch the Sentry scope
 * themselves. `environment` and `release` are native Sentry event fields
 * populated from config/sentry.php, so they are deliberately not duplicated as
 * tags — only the two facts Sentry has no native field for are tagged.
 */
final class ObservabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
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
