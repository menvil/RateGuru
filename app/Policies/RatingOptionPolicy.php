<?php

namespace App\Policies;

use App\Models\RatingOption;
use App\Models\User;

class RatingOptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAdminister($user);
    }

    public function view(User $user, RatingOption $option): bool
    {
        return $this->canAdminister($user);
    }

    public function create(User $user): bool
    {
        return $this->canAdminister($user);
    }

    public function update(User $user, RatingOption $option): bool
    {
        return $this->canAdminister($user);
    }

    public function delete(User $user, RatingOption $option): bool
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
