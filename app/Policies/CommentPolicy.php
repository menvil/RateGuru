<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function delete(User $user, Comment $comment): bool
    {
        // Owners can always delete their own comment; admins can delete any
        // comment so they can act on abuse from the Filament moderation table.
        // Moderators intentionally cannot delete — they use hide/restore.
        return $comment->user_id === $user->id || $user->isAdmin();
    }

    public function hide(User $user, Comment $comment): bool
    {
        return $this->canModerate($user);
    }

    public function restore(User $user, Comment $comment): bool
    {
        return $this->canModerate($user);
    }

    private function canModerate(User $user): bool
    {
        return $user->isModerator() || $user->isAdmin();
    }
}
