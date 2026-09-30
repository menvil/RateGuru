<?php

namespace App\Support\Auth;

/**
 * The one rule about removing a way to sign in: an account always keeps at
 * least one — its password, or another connected provider. Shared by the
 * action that enforces it and the profile card that explains it, so the two
 * can never disagree.
 */
final class SignInMethods
{
    public static function remainAfterDisconnecting(bool $hasPassword, int $otherConnectedProviders): bool
    {
        return $hasPassword || $otherConnectedProviders > 0;
    }
}
