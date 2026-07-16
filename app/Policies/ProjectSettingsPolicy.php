<?php

namespace App\Policies;

use App\Models\User;

final class ProjectSettingsPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }
}
