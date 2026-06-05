<?php

namespace App\Policies;

use App\Models\RatingGroup;
use App\Models\User;

class RatingGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, RatingGroup $group): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, RatingGroup $group): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, RatingGroup $group): bool
    {
        return false;
    }
}
