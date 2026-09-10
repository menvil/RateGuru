<?php

namespace App\Actions\Auth;

use App\Data\Auth\SocialIdentity;
use App\Data\Auth\SocialLoginResult;
use App\Exceptions\Auth\SocialAuthenticationException;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;

/**
 * Decides what a provider callback means for RateGuru accounts.
 *
 * The external identity (provider + provider_user_id) is the key, never the
 * email: a known identity signs its account in, an unknown identity either
 * becomes a new account or — when its email already belongs to someone — is
 * parked as a pending link until that someone signs in. An already
 * authenticated person is connecting a provider to their own account instead.
 *
 * Two callbacks for the same identity or email can race; the unique indexes
 * settle who wins, and the loser re-reads once so it signs in to the row the
 * winner created instead of failing with a constraint error.
 */
final class ResolveSocialLoginAction
{
    private const int MAX_RESOLUTION_ATTEMPTS = 2;

    public function __construct(
        private readonly RegisterSocialUserAction $registerSocialUser,
        private readonly LinkSocialAccountAction $linkSocialAccount,
        private readonly StorePendingSocialLinkAction $storePendingSocialLink,
    ) {}

    /**
     * @param  User|null  $actor  the account already signed in, if any
     *
     * @throws SocialAuthenticationException for every controlled refusal
     */
    public function execute(SocialIdentity $identity, ?User $actor, Session $session): SocialLoginResult
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $actor === null
                    ? $this->resolveForGuest($identity, $session)
                    : $this->resolveForAuthenticated($identity, $actor);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::MAX_RESOLUTION_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    private function resolveForGuest(SocialIdentity $identity, Session $session): SocialLoginResult
    {
        $account = $this->findAccount($identity);

        if ($account !== null) {
            $user = $account->user;

            // The same lifecycle gate as a password login: living sanctions
            // may sign in, a Deleted tombstone may not — refused with the
            // generic failure, never revealing that a tombstone exists.
            if ($user === null || ! $user->canAuthenticate()) {
                throw SocialAuthenticationException::accountUnavailable($identity->provider);
            }

            Auth::login($user);

            return SocialLoginResult::loggedIn($user);
        }

        if ($identity->email === null) {
            // No placeholder, no synthetic address: without an email there is
            // no way to tell whether this person already has an account.
            throw SocialAuthenticationException::emailMissing($identity->provider);
        }

        $existing = User::query()->where('email', $identity->email)->first();

        if ($existing !== null) {
            if (! $existing->canAuthenticate()) {
                throw SocialAuthenticationException::accountUnavailable($identity->provider);
            }

            // The email is a claim, not a proof: the person must sign in to the
            // account that owns it before the identity is attached.
            $this->storePendingSocialLink->execute($identity, $session);

            return SocialLoginResult::pendingLink();
        }

        $user = $this->registerSocialUser->execute($identity);

        Auth::login($user);

        return SocialLoginResult::registered($user);
    }

    private function resolveForAuthenticated(SocialIdentity $identity, User $actor): SocialLoginResult
    {
        $this->linkSocialAccount->execute($actor, $identity);

        return SocialLoginResult::linked($actor);
    }

    private function findAccount(SocialIdentity $identity): ?SocialAccount
    {
        return SocialAccount::query()
            ->where('provider', $identity->provider->value)
            ->where('provider_user_id', $identity->providerUserId)
            ->with('user')
            ->first();
    }
}
