<?php

namespace App\Actions\Auth;

use App\Data\Auth\PendingSocialLink;
use App\Exceptions\Auth\SocialAuthenticationException;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Auth\SocialIdentityNormalizer;
use Illuminate\Contracts\Session\Session;

/**
 * Claims a parked social identity for the account that just proved
 * ownership by signing in — with a password, or with a provider that is
 * already linked. Called explicitly by every successful authentication path
 * rather than from a listener, because "who may end up linked to whom" is
 * account-linking business logic and must be visible where sign-in happens.
 *
 * The pending state is consumed whatever the outcome (one-time), and the
 * link only happens when the signed-in account's email is the email the
 * identity carried: signing in to a different account links nothing.
 */
final class CompletePendingSocialLinkAction
{
    public function __construct(
        private readonly LinkSocialAccountAction $linkSocialAccount,
        private readonly SocialIdentityNormalizer $normalizer,
    ) {}

    /** The linked account, or null when nothing was (or could be) linked. */
    public function execute(User $user, Session $session): ?SocialAccount
    {
        $pending = PendingSocialLink::fromSession($session->pull(PendingSocialLink::SESSION_KEY));

        if ($pending === null || $pending->isExpired(now())) {
            return null;
        }

        if ($this->normalizer->normalizeEmail($user->email) !== $pending->email) {
            return null;
        }

        try {
            return $this->linkSocialAccount->execute($user, $pending->toIdentity());
        } catch (SocialAuthenticationException) {
            // A conflict discovered only now — the identity was claimed
            // meanwhile, the account already holds this provider — must not
            // undo the sign-in that just succeeded; the person is simply not
            // linked and can start the provider flow again while signed in.
            return null;
        }
    }
}
