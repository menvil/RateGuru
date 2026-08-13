<?php

namespace App\Policies;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function create(User $user): bool
    {
        return $user->canCreateContent();
    }

    public function update(User $user, Post $post): bool
    {
        // Editing a draft is authoring: a sanctioned owner may not touch
        // it even though the post never went public (PR-F).
        return $user->canCreateContent()
            && $post->user_id === $user->id
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

    /**
     * Author deletion is owner-only: admin/moderator roles act through
     * Hide/Restore moderation, never through the author-retention path.
     */
    public function deleteFromFeed(User $user, Post $post): bool
    {
        return $user->canManageContent()
            && (int) $post->user_id === (int) $user->id;
    }

    /**
     * Author self-service restore of an author-deleted post. Owner-only,
     * mirroring deleteFromFeed; distinct from the moderation `restore`
     * ability above, which targets Hidden posts.
     */
    public function restoreDeleted(User $user, Post $post): bool
    {
        return $user->canManageContent()
            && (int) $post->user_id === (int) $user->id;
    }

    public function report(User $user, Post $post): bool
    {
        return $user->canReport()
            && (int) $post->user_id !== (int) $user->id;
    }

    public function vote(User $user, Post $post): bool
    {
        return $user->canVote()
            && (int) $post->user_id !== (int) $user->id;
    }

    private function canModerate(User $user): bool
    {
        return $user->isModerator() || $user->isAdmin();
    }
}
