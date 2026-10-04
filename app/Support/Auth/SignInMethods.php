<?php

namespace App\Support\Auth;

/**
 * The one rule about removing a way to sign in: an account always keeps at
 * least one that works — its password, or another connected provider that
 * is currently available. A provider switched off in Project settings does
 * not count: it would leave the account with no way in until it is switched
 * back on. Shared by the action that enforces it and the profile card that
 * explains it, so the two can never disagree.
 */
final class SignInMethods
{
    public static function remainAfterDisconnecting(bool $hasPassword, int $otherAvailableProviders): bool
    {
        return $hasPassword || $otherAvailableProviders > 0;
    }
}
