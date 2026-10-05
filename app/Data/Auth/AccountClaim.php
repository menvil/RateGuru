<?php

namespace App\Data\Auth;

use App\Models\User;

final readonly class AccountClaim
{
    public function __construct(
        public User $user,
        /**
         * The account's email was unconfirmed, so it was confirmed here and the
         * credentials on it were revoked: sessions, remember token,
         * password-reset tokens, and every provider link that cannot prove it
         * owns this address.
         *
         * A link survives only on evidence — its own provider confirmed the same
         * address. Absence of a password is NOT evidence: an account can be
         * created from an address a provider never confirmed, which is how it
         * comes to be unconfirmed in the first place.
         */
        public bool $secured,
        /**
         * A password set by whoever created the unconfirmed account was removed.
         *
         * Reported separately because the person signing in is told about it —
         * they can set a new one. It is no longer what decides whether provider
         * links are revoked; see $secured.
         */
        public bool $passwordRemoved,
    ) {}
}
