<?php

namespace App\Support\Games\Play;

use App\Enums\UserStatus;
use App\Models\User;

/**
 * The name a result card carries for the player who earned it: their public
 * username with its at sign, while their account is in good standing. A
 * guest, a persona, or an account banned, limited or deleted has none, and
 * the card names the site instead: a shared card puts the name in front of
 * anyone.
 */
final class PlayerHandle
{
    public static function of(?User $user): ?string
    {
        if ($user === null || ! $user->canSignIn() || $user->status !== UserStatus::Active || $user->public_username === null) {
            return null;
        }

        return '@'.$user->public_username;
    }
}
