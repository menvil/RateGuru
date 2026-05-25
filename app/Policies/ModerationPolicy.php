<?php

namespace App\Policies;

use App\Models\User;

class ModerationPolicy
{
    public function moderateContent(User $user): bool
    {
        return false;
    }

    public function banUser(User $user): bool
    {
        return false;
    }
}
