<?php

namespace App\Policies;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function delete(User $user, Comment $comment): bool
    {
        // Author deletion is owner-only. Admins/moderators act through
        // hide/restore — deletion is an authored-content decision, not a
        // moderation shortcut, and there is deliberately no admin delete.
        // Lifecycle capability required (PR-F): a sanctioned author cannot
        // manage their community content while restricted.
        return $user->canManageContent()
            && $comment->user_id === $user->id;
    }

    public function hide(User $user, Comment $comment): bool
    {
        // Only a live, visible comment can be hidden: an author-deleted row
        // has left the moderation lifecycle entirely.
        return $this->canModerate($user)
            && ! $comment->trashed()
            && $comment->status === CommentStatus::Visible;
    }

    public function restore(User $user, Comment $comment): bool
    {
        // Restore only reverses a moderation hide. It must never resurrect
        // an author-deleted comment.
        return $this->canModerate($user)
            && ! $comment->trashed()
            && $comment->status === CommentStatus::Hidden;
    }

    private function canModerate(User $user): bool
    {
        // Role AND lifecycle (PR-F): a sanctioned moderator/admin loses
        // moderation capability until restored to Active.
        return ($user->isModerator() || $user->isAdmin())
            && $user->canAccessPrivilegedPanel();
    }
}
