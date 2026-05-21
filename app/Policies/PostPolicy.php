<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function approve(User $user, Post $post): bool
    {
        return $this->canModerate($user);
    }

    public function reject(User $user, Post $post): bool
    {
        return $this->canModerate($user);
    }

    public function hide(User $user, Post $post): bool
    {
        return $this->canModerate($user);
    }

    public function restore(User $user, Post $post): bool
    {
        return $this->canModerate($user);
    }

    /**
     * Deleting a post is destructive and is reserved for admins; moderators
     * use hide/restore instead.
     */
    public function delete(User $user, Post $post): bool
    {
        return $user->isAdmin();
    }

    private function canModerate(User $user): bool
    {
        return $user->isModerator() || $user->isAdmin();
    }
}
