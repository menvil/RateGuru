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
        $count = Comment::query()
            ->where('post_id', $post->id)
            ->where('status', CommentStatus::Visible)
            ->count();

        $post->forceFill(['comments_count' => $count])->save();
    }
}
