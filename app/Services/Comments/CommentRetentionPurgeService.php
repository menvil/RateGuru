<?php

namespace App\Services\Comments;

use App\Enums\CommentPurgeOutcome;
use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Support\ContentLifecycle\CommentRetention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Author-deleted comment retention (docs/architecture/comment-lifecycle.md):
 * a purely author-deleted leaf comment is physically removed after its
 * retention window, but every hold wins over the clock — structural
 * anchors (any child row, trashed included), parent-post author retention
 * (PR-E restore must recover the untouched discussion graph), parent-post
 * moderation states (post-level cleanup owns the graph), moderation
 * evidence (a comment still Hidden or finalized never enters ordinary
 * author cleanup) and open reports.
 */
final class CommentRetentionPurgeService
{
    public function __construct(
        private readonly CommentPhysicalDeletionService $physicalDeletion,
    ) {}

    /**
     * Rows that look purge-eligible. Never authoritative: purge() re-reads
     * everything under lock (Post -> Comment) and repeats every check.
     */
    public function candidates(?int $olderThanDays = null): Builder
    {
        return Comment::onlyTrashed()
            ->where('status', CommentStatus::Visible)
            ->whereNull('moderation_removed_at')
            ->where('deleted_at', '<=', $this->cutoff($olderThanDays));
    }

    public function purge(int $commentId, ?int $olderThanDays = null, bool $dryRun = false): CommentPurgeOutcome
    {
        // Resolve retention before touching anything: bad config fails
        // closed here, never mid-transaction.
        $cutoff = $this->cutoff($olderThanDays);

        return DB::transaction(function () use ($commentId, $cutoff, $dryRun): CommentPurgeOutcome {
            // Unlocked pre-read only to learn the parent post id; the
            // authoritative re-read happens under lock in Post -> Comment
            // order (post_id is immutable on comments).
            $preview = Comment::withTrashed()->find($commentId);

            if ($preview === null) {
                return CommentPurgeOutcome::AlreadyGone;
            }

            $postQuery = Post::withTrashed()->whereKey($preview->post_id);
            $commentQuery = Comment::withTrashed()->whereKey($commentId);

            if (! $dryRun) {
                $postQuery->lockForUpdate();
                $commentQuery->lockForUpdate();
            }

            $post = $postQuery->first();
            $comment = $commentQuery->first();

            if ($comment === null) {
                return CommentPurgeOutcome::AlreadyGone;
            }

            // Only the pure author-deleted shape enters ordinary cleanup:
            // soft-deleted, still Visible (a Hidden row is active moderation
            // evidence) and never finalized.
            if (
                ! $comment->trashed()
                || $comment->status !== CommentStatus::Visible
                || $comment->moderation_removed_at !== null
            ) {
                return CommentPurgeOutcome::InvalidState;
            }

            // Parent post holds: an author-retained post must restore its
            // exact discussion graph; hidden/finalized posts hand the graph
            // to post-level moderation cleanup.
            if ($post === null || $post->trashed()) {
                return CommentPurgeOutcome::PostRetentionHold;
            }

            if ($post->status === PostStatus::Hidden) {
                return CommentPurgeOutcome::PostModerationHold;
            }

            if ($comment->deleted_at->gt($cutoff)) {
                return CommentPurgeOutcome::NotExpired;
            }

            // Structural anchor: any child row, trashed included, keeps the
            // thread shape and the public tombstone rendering intact.
            $hasChildren = Comment::withTrashed()
                ->where('parent_id', $comment->id)
                ->exists();

            if ($hasChildren) {
                return CommentPurgeOutcome::StructuralAnchor;
            }

            $hasOpenReport = Report::query()
                ->where('status', ReportStatus::Open)
                ->where('target_type', Comment::class)
                ->where('target_id', $comment->id)
                ->exists();

            if ($hasOpenReport) {
                return CommentPurgeOutcome::OpenReportHold;
            }

            if ($dryRun) {
                return CommentPurgeOutcome::WouldPurge;
            }

            $this->physicalDeletion->deleteLeaf($comment);

            return CommentPurgeOutcome::Purged;
        });
    }

    private function cutoff(?int $olderThanDays): Carbon
    {
        $days = $olderThanDays ?? CommentRetention::authorDeleteDays();

        if ($days < 0) {
            throw new InvalidArgumentException("olderThanDays must not be negative, got [{$days}].");
        }

        return now()->subDays($days);
    }
}
