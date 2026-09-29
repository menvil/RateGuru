<?php

namespace App\Actions\Auth;

use App\Enums\SocialProvider;
use App\Exceptions\Auth\CannotDisconnectSocialAccountException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\SocialAccountDisconnectedNotification;
use App\Support\Auth\SignInMethods;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;

/**
 * Removes a Google or Facebook sign-in from an account.
 *
 * Never the last way in: an account without a password keeps at least one
 * provider. Decided on the locked account row, so two disconnects racing
 * each other cannot both succeed and leave the account unreachable.
 */
final class UnlinkSocialAccountAction
{
    use LocksActorForWrite;

    public function __construct(
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @throws CannotDisconnectSocialAccountException
     */
    public function execute(User $user, SocialProvider $provider): void
    {
        $removed = DB::transaction(function () use ($user, $provider): SocialAccount {
            $locked = $this->lockActor($user);

            if ($locked === null || ! $locked->canAuthenticate()) {
                throw CannotDisconnectSocialAccountException::accountUnavailable($provider);
            }

            $account = SocialAccount::query()
                ->where('user_id', $locked->id)
                ->where('provider', $provider->value)
                ->first();

            if ($account === null) {
                throw CannotDisconnectSocialAccountException::notConnected($provider);
            }

            $otherProviders = SocialAccount::query()
                ->where('user_id', $locked->id)
                ->where('provider', '!=', $provider->value)
                ->count();

            if (! SignInMethods::remainAfterDisconnecting($locked->hasPassword(), $otherProviders)) {
                throw CannotDisconnectSocialAccountException::lastSignInMethod($provider);
            }

            $account->delete();

            return $account;
        });

        $user->notify(new SocialAccountDisconnectedNotification($provider, $removed->provider_email));

        // Deliberately PII-free: no provider subject, no email.
        $this->logger->info('auth.social_account_disconnected', [
            'user_id' => $user->getKey(),
            'provider' => $provider->value,
        ]);
    }
}
