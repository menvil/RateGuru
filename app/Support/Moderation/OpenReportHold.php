<?php

namespace App\Support\Moderation;

use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether an open report still holds content a purge is about to delete.
 *
 * Every report on the content is locked — the closed ones too — before
 * their status is read. A plain read lets a moderator reopen a closed report
 * between the check and the deletion: the purge then deletes the reopened
 * report with its post's graph, or leaves it pointing at a comment that no
 * longer exists. Locked, the reopen either commits first and is seen here as
 * open, or waits and then finds nothing left to reopen.
 *
 * The purges call this holding the post and comment locks already, so the
 * reports come last, where the lock order puts edges. A dry run locks
 * nothing.
 */
final class OpenReportHold
{
    public function onComment(int $commentId, bool $lock): bool
    {
        return $this->anyOpen(
            Report::query()->where('target_type', Comment::class)->where('target_id', $commentId),
            $lock,
        );
    }

    /** On the post itself, or on any of its comments — deleted ones included. */
    public function onPostGraph(int $postId, bool $lock): bool
    {
        return $this->anyOpen(
            Report::query()->where(fn (Builder $targets) => $targets
                ->where(fn (Builder $post) => $post->where('target_type', Post::class)->where('target_id', $postId))
                ->orWhere(fn (Builder $comments) => $comments
                    ->where('target_type', Comment::class)
                    ->whereIn('target_id', Comment::withTrashed()->where('post_id', $postId)->select('id')))),
            $lock,
        );
    }

    /** @param  Builder<Report>  $reports */
    private function anyOpen(Builder $reports, bool $lock): bool
    {
        if (! $lock) {
            return $reports->where('status', ReportStatus::Open)->exists();
        }

        // Every row locked, the closed ones included, and only then read.
        $statuses = $reports->lockForUpdate()->pluck('status');

        return $statuses->contains(ReportStatus::Open);
    }
}
