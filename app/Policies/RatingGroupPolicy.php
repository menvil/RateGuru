<?php

namespace App\Policies;

use App\Models\RatingGroup;
use App\Models\User;

class RatingGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAdminister($user);
    }

    public function view(User $user, RatingGroup $group): bool
    {
        return $this->canAdminister($user);
    }

    public function create(User $user): bool
    {
        return $this->canAdminister($user);
    }

    public function update(User $user, RatingGroup $group): bool
    {
        return $this->canAdminister($user);
    }

    public function delete(User $user, RatingGroup $group): bool
    {
        return false;
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
