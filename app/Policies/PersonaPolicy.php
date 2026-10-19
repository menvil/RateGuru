<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who makes and edits Personas in Content Studio: an active administrator,
 * and nobody else. Getting into the admin panel is not enough — a moderator
 * gets in too — and a sanctioned administrator loses this with the panel.
 *
 * The Content Studio actions ask again on the locked, current row of the
 * administrator, so a request that started before a demotion or a sanction
 * writes nothing.
 */
final class PersonaPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin()
            && $user->canAccessPrivilegedPanel();
    }
}
