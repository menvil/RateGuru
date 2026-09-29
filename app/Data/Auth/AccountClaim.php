<?php

namespace App\Data\Auth;

use App\Models\User;

final readonly class AccountClaim
{
    public function __construct(
        public User $user,
        /** The account's email was unconfirmed, and everything its creator could still hold was revoked. */
        public bool $secured,
        /** A password set by whoever created the unconfirmed account was removed. */
        public bool $passwordRemoved,
    ) {}
}
