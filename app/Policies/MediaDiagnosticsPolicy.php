<?php

namespace App\Policies;

use App\Models\User;

final class MediaDiagnosticsPolicy
{
    public function view(User $user): bool
    {
        return $this->canAdminister($user);
    }

    /**
     * Role AND lifecycle, as CategoryPolicy and the rest of the panel apply:
     * a sanctioned administrator keeps the role and loses the capability
     * until restored.
     */
    private function canAdminister(User $user): bool
    {
        return $user->isAdmin() && $user->canAccessPrivilegedPanel();
    }
}
