<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who runs Growth → Traffic sources — the networks, campaigns, prices,
 * postbacks and blocks — an active administrator, and nobody else. The
 * traffic actions ask again on the locked, current row of the
 * administrator.
 */
final class TrafficSourcePolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin()
            && $user->canAccessPrivilegedPanel();
    }
}
