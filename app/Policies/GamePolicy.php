<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who builds and runs games in Growth → Games: an active administrator, and
 * nobody else. The game actions ask again on the locked, current row of the
 * administrator, so a request that started before a demotion or a sanction
 * writes nothing.
 */
final class GamePolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin()
            && $user->canAccessPrivilegedPanel();
    }
}
