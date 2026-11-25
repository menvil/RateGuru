<?php

namespace App\Actions\Comments\Concerns;

use App\Actions\Ranking\RecalculatePostScoreAction;
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
     *
     * The CALLER must already hold the posts row lock (every comment writer
     * locks Actor User -> Post -> Comment): acquiring the post lock here,
     * after the comment lock, would reverse the global order and deadlock
     * against writers holding Post while waiting for Comment.
     */
    protected function refreshCommentsCount(Post $lockedPost): void
    {
        $count = Comment::query()
            ->where('post_id', $lockedPost->id)
            ->where('status', CommentStatus::Visible)
            ->count();

        // A post's comments count towards its language: it is worked out again in the background (languages:work),
        // marked here in the one update this write makes of the post.
        $lockedPost->forceFill(['comments_count' => $count, 'language_stale_at' => now()]);

        // The hot score counts comments, so it moves with the count — when a
        // comment is added, deleted, hidden or restored alike, under the
        // same post lock, and saved with it in one update of the post, which
        // never locks its author after it.
        app(RecalculatePostScoreAction::class)->saveWithCounts($lockedPost);
    }
}
