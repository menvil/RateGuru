<?php

namespace App\Data\Auth;

use App\Models\User;

final readonly class AccountClaim
{
    public function __construct(
        public User $user,
        /**
         * The account's email was unconfirmed, so it was confirmed here and the
         * sessions, remember token and password-reset tokens on it were revoked.
         *
         * Note what this does NOT claim on its own: provider links survive, which
         * is correct for an account created through a provider — an existing link
         * there is most likely the same person on a second provider, and removing
         * it would lock them out. Links are revoked only alongside a password; see
         * $passwordRemoved.
         */
        public bool $secured,
        /**
         * A password set by whoever created the unconfirmed account was removed.
         *
         * This is the takeover case: a password on an unconfirmed address means
         * somebody chose a secret for an address they did not control, so every
         * credential on the account was theirs. Provider links are revoked here
         * too — otherwise removing the password would still leave them a way in.
         */
        public bool $passwordRemoved,
    ) {}
}
