<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function manage(User $actor, User $target): bool
    {
        return $actor->role === UserRole::Admin
            && $actor->id !== $target->id
            && $target->role !== UserRole::Admin;
    }

    public function ban(User $actor, User $target): bool
    {
        return $actor->role === UserRole::Admin
            && $actor->id !== $target->id
            && $target->role !== UserRole::Admin;
    }

    public function viewAdmin(User $actor): bool
    {
        return in_array($actor->role, [UserRole::Admin, UserRole::Moderator], true);
    }
}
