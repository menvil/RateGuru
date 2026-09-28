<?php

namespace App\Actions\Auth;

use App\Data\Auth\SocialIdentity;
use App\Exceptions\Auth\SocialAuthenticationException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Auth\SocialIdentityNormalizer;
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
 *  - the identity's email must equal the account's email — an identity is
 *    never attached to an account with a different address;
 *  - an identity already owned by someone else is never reassigned.
 *
 * The two unique indexes on social_accounts are the last line of defence
 * against callbacks racing each other; a violation surfaces as the same
 * controlled conflict the checks above would have reported.
 */
final class LinkSocialAccountAction
{
    use LocksActorForWrite;

    public function __construct(
        private readonly SocialIdentityNormalizer $normalizer,
    ) {}

    /**
     * @throws SocialAuthenticationException when the link would break one of the invariants above
     */
    public function execute(User $user, SocialIdentity $identity): SocialAccount
    {
        return DB::transaction(function () use ($user, $identity): SocialAccount {
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
                    return $current;
                }

                throw SocialAuthenticationException::providerAlreadyLinked($identity->provider);
            }

            if ($identity->email === null || $this->normalizer->normalizeEmail($locked->email) !== $identity->email) {
                throw SocialAuthenticationException::emailMismatch($identity->provider);
            }

            $ownedElsewhere = SocialAccount::query()
                ->where('provider', $identity->provider->value)
                ->where('provider_user_id', $identity->providerUserId)
                ->exists();

            if ($ownedElsewhere) {
                throw SocialAuthenticationException::identityAlreadyLinked($identity->provider);
            }

            try {
                return SocialAccount::create([
                    'user_id' => $locked->id,
                    'provider' => $identity->provider,
                    'provider_user_id' => $identity->providerUserId,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The account row is locked, so its (user_id, provider) slot
                // cannot race; only the identity itself can have been claimed
                // by another account since the check above.
                throw SocialAuthenticationException::identityAlreadyLinked($identity->provider);
            }
        });
    }
}
