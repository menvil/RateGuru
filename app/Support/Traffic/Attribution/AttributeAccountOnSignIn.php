<?php

namespace App\Support\Traffic\Attribution;

use App\Actions\Traffic\RecordAccountLoginAction;
use App\Actions\Traffic\RecordAccountSignupAction;
use App\Enums\AccountSignupMethod;
use App\Models\TrafficSetting;
use App\Models\User;
use App\Support\Games\Play\GameVisitorResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Throwable;

/**
 * A new account records what it will be credited to; a sign-in to an
 * existing one records that the browser is its owner's. Runs after the
 * games claim their guest attempts, inside the registration and sign-in
 * responses: whatever goes wrong is reported, never allowed to stop anybody
 * signing in. While visits are not recorded no visitor cookie is issued:
 * only a browser that already has one is looked at.
 */
final class AttributeAccountOnSignIn
{
    public function __construct(
        private readonly GameVisitorResolver $visitors,
        private readonly SignupOrigin $origin,
        private readonly RecordAccountSignupAction $signup,
        private readonly RecordAccountLoginAction $login,
    ) {}

    public function handle(Registered|Login $event): void
    {
        if (($event instanceof Login && $event->guard !== 'web') || ! $event->user instanceof User) {
            return;
        }

        try {
            $browserHash = TrafficSetting::current()->capture_enabled ? $this->visitors->ensureBrowserHash() : $this->visitors->browserHash();
            $now = CarbonImmutable::now();

            $event instanceof Registered
                ? $this->signup->handle($event->user, $browserHash, self::methodOf($event->user), $this->origin->place(), $now)
                : $this->login->handle($event->user, $browserHash, $now);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function methodOf(User $user): AccountSignupMethod
    {
        $provider = $user->socialAccounts()->orderBy('id')->first()?->provider;

        return $provider === null ? AccountSignupMethod::Email : AccountSignupMethod::from($provider->value);
    }
}
