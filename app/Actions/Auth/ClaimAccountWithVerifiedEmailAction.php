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

            $secured = ! $locked->hasVerifiedEmail();
            $passwordRemoved = $secured && $locked->hasPassword();

            // Revoked BEFORE the link, not after, and this ordering is the whole
            // point of it.
            //
            // LinkSocialAccountAction refuses an identity of a provider the account
            // already holds a DIFFERENT subject for — correctly, as a connect
            // operation: it must never silently replace somebody's Google account
            // with another. But a claim is not a connect. Running it first meant an
            // unconfirmed account carrying an unproven Google link refused the real
            // owner arriving with a confirmed Google identity: the claim aborted,
            // the account stayed unconfirmed, and the link that could prove nothing
            // kept working. The one case the new model exists for was the one case
            // it could not reach.
            //
            // So the credentials that cannot prove they own this address go first,
            // including one of the incoming provider, and the link then happens
            // against an account with nothing in its way.
            if ($secured) {
                $this->revokeUnprovenIdentities($locked, $identity);
            }

            // Every linking rule still applies to what is left: the identity is
            // never taken from another account, and a provider whose remaining
            // identity DID prove this address is still a conflict rather than a
            // replacement. A refusal rolls back everything with it.
            $linked = $this->linkSocialAccount->execute($locked, $identity);

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

    /**
     * Removes every sign-in identity on this account that cannot prove it owns the
     * address being claimed.
     *
     * A link survives only on evidence: its own provider confirmed the SAME
     * address. One the provider did not confirm, one pointing at a different
     * mailbox, one with no address at all, and one written before that proof was
     * recorded are all revoked — the last because an absent record is not a record
     * of consent. The cost is one re-link for a person whose link was genuinely
     * theirs; the cost of the other choice is a stranger keeping access to an
     * account its owner has just confirmed.
     *
     * Two identities of the incoming provider need saying separately:
     *
     *  - the same subject is the incoming identity itself, arriving again. Left
     *    alone: the link below refreshes its recorded proof.
     *  - a different subject that CANNOT prove the address is revoked, which is
     *    what makes the claim possible at all — otherwise linking refuses and the
     *    unproven link outlives the owner's attempt to take the account back.
     *  - a different subject that CAN prove it is left in place, and the link then
     *    refuses. Two providers' confirmations of one address pointing at two
     *    subjects is a contradiction between authorities, not something to resolve
     *    by preferring whoever happened to sign in second.
     */
    private function revokeUnprovenIdentities(User $locked, SocialIdentity $identity): void
    {
        $claimed = $this->normalizer->normalizeEmail($identity->email);

        foreach ($locked->socialAccounts()->get() as $other) {
            $sameProvider = $other->provider === $identity->provider;

            if ($sameProvider && $other->provider_user_id === $identity->providerUserId) {
                continue;
            }

            if ($other->provesOwnershipOf($claimed)) {
                continue;
            }

            $other->delete();
        }
    }
}
