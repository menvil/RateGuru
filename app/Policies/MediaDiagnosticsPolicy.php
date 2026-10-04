<?php

namespace App\Policies;

use App\Models\User;

final class MediaDiagnosticsPolicy
{
    public function view(User $user): bool
    {
        return $user->isAdmin();
    }
}
