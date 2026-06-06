<?php

namespace App\Policies;

use App\Models\RatingOption;
use App\Models\User;

class RatingOptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, RatingOption $option): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, RatingOption $option): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, RatingOption $option): bool
    {
        return $user->isAdmin();
    }
}
