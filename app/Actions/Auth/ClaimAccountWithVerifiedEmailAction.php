<?php

namespace App\Actions\Auth;

use App\Data\Auth\AccountClaim;
use App\Data\Auth\SocialIdentity;
use App\Exceptions\Auth\SocialAuthenticationException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\PasswordResetToken;
use App\Models\Session;
use App\Models\User;
use App\Support\Auth\SessionGeneration;
use App\Support\Auth\SocialIdentityNormalizer;
use App\Support\Observability\DomainLogger;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Signs a provider identity into the existing account that uses the same
 * email address, when the provider itself has confirmed that address.
 *
 * The provider has just proven that this person controls the mailbox. If
 * the account's own email was confirmed too, it is simply the same person
 * signing in another way: the identity is linked and nothing else changes.
 *
 * If the account's email was never confirmed, whoever created the account
 * never proved they own that mailbox — it may have been someone else,
 * registering the address first to wait for its owner. The owner takes the
 * account over, and everything its creator could still use is revoked in
 * the same transaction: the password, every other session (including
 * "remember me"), and any password reset link. The email becomes confirmed.
 */
final class ClaimAccountWithVerifiedEmailAction
{
    use LocksActorForWrite;

    public function __construct(
        private readonly LinkSocialAccountAction $linkSocialAccount,
        private readonly SocialIdentityNormalizer $normalizer,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @throws SocialAuthenticationException when the account cannot take this identity
     */
    public function execute(User $user, SocialIdentity $identity): AccountClaim
    {
        if (! $identity->emailVerifiedByProvider || $identity->email === null) {
            throw new InvalidArgumentException('Only an email the provider has confirmed may claim an existing account.');
        }

        $claim = DB::transaction(function () use ($user, $identity): AccountClaim {
            $locked = $this->lockActor($user);

            if ($locked === null || ! $locked->canAuthenticate()) {
                throw SocialAuthenticationException::accountUnavailable($identity->provider);
            }

            // The account was found by email BEFORE this lock was taken, and what
            // this operation does is treat the provider's confirmation of that
            // address as proof of ownership — then verify the account's email and
            // revoke its credentials.
            //
            // So the address has to still be the one that was proved. A profile
            // email change landing in that window would otherwise have this
            // verifying the NEW address, and deleting its password-reset tokens,
            // on the strength of a proof about the old one. Re-read under the lock
            // and refuse if it moved; the operator can retry, and the retry finds
            // the account by its current address or not at all.
            //
            // CompletePendingSocialLinkAction already guards the same way for the
            // same reason; this is that rule applied where the stakes are higher.
            if ($this->normalizer->normalizeEmail($locked->email) !== $this->normalizer->normalizeEmail($identity->email)) {
                throw SocialAuthenticationException::accountUnavailable($identity->provider);
            }

            // Every linking rule applies: the identity is never taken from
            // another account and never replaces one of the same provider.
            // A refusal rolls back everything below with it.
            $linked = $this->linkSocialAccount->execute($locked, $identity);

            $secured = ! $locked->hasVerifiedEmail();
            $passwordRemoved = $secured && $locked->hasPassword();

            if ($secured) {
                $email = (string) $locked->email;

                $locked->forceFill([
                    'email_verified_at' => now(),
                    'password' => null,
                    'remember_token' => Str::random(60),
                    'session_generation' => SessionGeneration::next(),
                ])->save();

                Session::query()->where('user_id', $locked->id)->delete();
                PasswordResetToken::query()->where('email', $email)->delete();

                // And, when a PASSWORD was removed, every other provider link.
                //
                // That condition is the whole subtlety. A password on an
                // unconfirmed account means somebody typed this address they did
                // not control and chose a secret for it — a takeover — so every
                // credential on it is the squatter's, provider links included.
                // Leaving one behind leaves them a working sign-in, because
                // ResolveSocialLoginAction signs in whatever account a known
                // identity points at.
                //
                // An unconfirmed account with NO password is the opposite case: it
                // was created through a provider, so an existing link is most
                // likely the same person arriving via a second provider. This
                // operation deliberately only CONFIRMS such an account rather than
                // taking it over, and deleting that link would lock out the person
                // it belongs to. See the "only confirms an unconfirmed account
                // that never had a password" case in SocialAccountClaimTest.
                //
                // The link just established is excluded by key either way: it
                // belongs to whoever is proving ownership now.
                if ($passwordRemoved) {
                    $locked->socialAccounts()
                        ->whereKeyNot($linked->getKey())
                        ->delete();
                }
            }

            $user->setRawAttributes($locked->getAttributes(), true);

            return new AccountClaim($user, $secured, $passwordRemoved);
        });

        if ($claim->secured) {
            event(new Verified($claim->user));
        }

        // Deliberately PII-free: no email, no provider subject.
        $this->logger->info('auth.account_claimed', [
            'user_id' => $claim->user->getKey(),
            'provider' => $identity->provider->value,
            'secured' => $claim->secured,
            'password_removed' => $claim->passwordRemoved,
        ]);

        return $claim;
    }
}
