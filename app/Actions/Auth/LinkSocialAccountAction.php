<?php

namespace App\Actions\Auth;

use App\Data\Auth\SocialIdentity;
use App\Exceptions\Auth\SocialAuthenticationException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\SocialAccountConnectedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Attaches a provider identity to an existing account.
 *
 * Every invariant of account linking is enforced here and nowhere else, on
 * the locked account row:
 *
 *  - only a living account (canAuthenticate) may gain an identity;
 *  - one identity per provider per account — an existing Google identity is
 *    never silently replaced by another;
 *  - an identity already owned by someone else is never reassigned;
 *  - an identity whose email is another account's address is never attached
 *    here — that address belongs with the other account.
 *
 * The identity's email need not be the account's own: a person signed in
 * to their account may connect a Google or Facebook account that uses
 * another address of theirs. Proof of ownership is the provider sign-in
 * performed while signed in, not the email.
 *
 * The two unique indexes on social_accounts are the last line of defence
 * against callbacks racing each other; a violation surfaces as the same
 * controlled conflict the checks above would have reported.
 *
 * A newly attached identity sends the account a security email once the
 * surrounding transaction commits, whichever path attached it.
 */
final class LinkSocialAccountAction
{
    use LocksActorForWrite;

    /**
     * @throws SocialAuthenticationException when the link would break one of the invariants above
     */
    public function execute(User $user, SocialIdentity $identity): SocialAccount
    {
        $account = DB::transaction(function () use ($user, $identity): SocialAccount {
            $locked = $this->lockActor($user);

            if ($locked === null || ! $locked->canAuthenticate()) {
                throw SocialAuthenticationException::accountUnavailable($identity->provider);
            }

            $current = SocialAccount::query()
                ->where('user_id', $locked->id)
                ->where('provider', $identity->provider->value)
                ->first();

            if ($current !== null) {
                if ($current->provider_user_id === $identity->providerUserId) {
                    // Already connected: a repeat is a no-op, not a conflict.
                    $current->refreshProviderEmail($identity->email);

                    return $current;
                }

                throw SocialAuthenticationException::providerAlreadyLinked($identity->provider);
            }

            $ownedElsewhere = SocialAccount::query()
                ->where('provider', $identity->provider->value)
                ->where('provider_user_id', $identity->providerUserId)
                ->exists();

            if ($ownedElsewhere) {
                throw SocialAuthenticationException::identityAlreadyLinked($identity->provider);
            }

            $emailOwnedElsewhere = $identity->email !== null && User::query()
                ->where('email', $identity->email)
                ->whereKeyNot($locked->id)
                ->exists();

            if ($emailOwnedElsewhere) {
                throw SocialAuthenticationException::emailBelongsToAnotherAccount($identity->provider);
            }

            try {
                return SocialAccount::create([
                    'user_id' => $locked->id,
                    'provider' => $identity->provider,
                    'provider_user_id' => $identity->providerUserId,
                    'provider_email' => $identity->email,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The account row is locked, so its (user_id, provider) slot
                // cannot race; only the identity itself can have been claimed
                // by another account since the check above.
                throw SocialAuthenticationException::identityAlreadyLinked($identity->provider);
            }
        });

        if ($account->wasRecentlyCreated) {
            $user->notify(new SocialAccountConnectedNotification($identity->provider, $identity->email));
        }

        return $account;
    }
}
