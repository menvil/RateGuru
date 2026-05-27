<?php

namespace App\Policies;

use App\Enums\PostStatus;
use App\Enums\UserStatus;
use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        return $post->user_id === $user->id
            && $post->status === PostStatus::Draft;
    }

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
        return $this->canModerate($user)
            && $post->status === PostStatus::Published;
    }

    public function restore(User $user, Post $post): bool
    {
        return $this->canModerate($user);
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->isAdmin();
    }

    public function deleteFromFeed(User $user, Post $post): bool
    {
        return $user->status === UserStatus::Active
            && ((int) $post->user_id === (int) $user->id
                || $user->isAdmin()
                || $user->isModerator());
    }

    public function report(User $user, Post $post): bool
    {
        return $user->canReport()
            && (int) $post->user_id !== (int) $user->id;
    }

    private function canModerate(User $user): bool
    {
        return $user->isModerator() || $user->isAdmin();
    }
}
