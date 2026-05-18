<?php

namespace App\Actions\Comments\Concerns;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;

trait RefreshesPostCommentsCount
{
    /**
     * Recalculate posts.comments_count from the authoritative source: the
     * number of visible, non-deleted comments. Using an absolute count rather
     * than increment/decrement keeps the counter self-healing if it ever
     * drifts.
     */
    protected function refreshCommentsCount(Post $post): void
    {
        // Pessimistically lock the posts row before counting. Callers run this
        // inside a DB::transaction, so a concurrent recalculation blocks here
        // until the first commits and then re-counts the already-committed
        // comment rows. Without the lock the read-then-write loses updates and
        // persists a stale comments_count. Mirrors RecalculatePostCountersAction.
        Post::query()
            ->whereKey($post->getKey())
            ->lockForUpdate()
            ->first();

        $count = Comment::query()
            ->where('post_id', $post->id)
            ->where('status', CommentStatus::Visible)
            ->count();

        $post->forceFill(['comments_count' => $count])->save();
    }
}
