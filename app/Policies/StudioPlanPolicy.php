<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who prepares publication plans in Content Studio: an active administrator,
 * and nobody else. A capability of its own, apart from managing Personas —
 * the two go to the same people today, and neither implies the other.
 *
 * The Content Studio actions ask again on the locked, current row of the
 * administrator, so a request that started before a demotion or a sanction
 * writes nothing.
 */
final class StudioPlanPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin()
            && $user->canAccessPrivilegedPanel();
    }
}
