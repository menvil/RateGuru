<?php

namespace App\Policies;

use App\Models\User;

class ModerationPolicy
{
    public function moderateContent(User $user): bool
    {
        return $user->isModerator() || $user->isAdmin();
    }

    public function banUser(User $user): bool
    {
        return $user->isAdmin();
    }
}
