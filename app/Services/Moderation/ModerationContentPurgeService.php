<?php

namespace App\Services\Moderation;

use App\Enums\CommentStatus;
use App\Enums\ModerationPurgeOutcome;
use App\Enums\PostStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Services\Comments\CommentPhysicalDeletionService;
use App\Services\Posts\PostGraphDeletionService;
use App\Support\ContentLifecycle\ModerationContentRetention;
use App\Support\Moderation\OpenReportHold;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Physical cleanup of FINALIZED moderation removals
 * (docs/architecture/moderation-content-lifecycle.md). The purge clock is
 * moderation_removed_at alone; reversible Hidden rows
 * (moderation_removed_at IS NULL) are never candidates and never
 * eligible. Retention is disabled by default: with no configured window
 * and no explicit override every purge reports RetentionDisabled and
 * writes nothing.
 */
final class ModerationContentPurgeService
{
    public function __construct(
        private readonly PostGraphDeletionService $graphDeletion,
        private readonly CommentPhysicalDeletionService $commentDeletion,
        private readonly OpenReportHold $openReports,
    ) {}

    public function postCandidates(int $olderThanDays): Builder
    {
        return Post::query()
            ->where('status', PostStatus::Hidden)
            ->whereNotNull('moderation_removed_at')
            ->where('moderation_removed_at', '<=', now()->subDays($olderThanDays));
    }

    public function commentCandidates(int $olderThanDays): Builder
    {
        return Comment::withTrashed()
            ->where('status', CommentStatus::Hidden)
            ->whereNotNull('moderation_removed_at')
            ->where('moderation_removed_at', '<=', now()->subDays($olderThanDays));
    }

    public function purgePost(int $postId, ?int $olderThanDays = null, bool $dryRun = false): ModerationPurgeOutcome
    {
        $cutoff = $this->cutoff($olderThanDays);

        if ($cutoff === null) {
            return ModerationPurgeOutcome::RetentionDisabled;
        }

        return DB::transaction(function () use ($postId, $cutoff, $dryRun): ModerationPurgeOutcome {
            $query = Post::withTrashed()->whereKey($postId);
            $post = $dryRun ? $query->first() : $query->lockForUpdate()->first();

            if ($post === null) {
                return ModerationPurgeOutcome::AlreadyGone;
            }

            // Only the finalized moderation shape is purgeable — never a
            // live, reversible-Hidden, author-deleted or malformed row.
            if (
                $post->trashed()
                || $post->status !== PostStatus::Hidden
                || $post->moderation_removed_at === null
            ) {
                return ModerationPurgeOutcome::InvalidState;
            }

            if ($post->moderation_removed_at->gt($cutoff)) {
                return ModerationPurgeOutcome::NotExpired;
            }

            // Active moderation evidence holds the purge: a review flag, or
            // an open report on the post or any of its comments. Closed
            // reports never hold it forever.
            if ($post->needs_review || $this->openReports->onPostGraph($post->id, lock: ! $dryRun)) {
                return ModerationPurgeOutcome::ModerationHold;
            }

            if ($dryRun) {
                return ModerationPurgeOutcome::WouldPurge;
            }

            $this->graphDeletion->deleteGraph($post);

            return ModerationPurgeOutcome::Purged;
        });
    }

    public function purgeComment(int $commentId, ?int $olderThanDays = null, bool $dryRun = false): ModerationPurgeOutcome
    {
        $cutoff = $this->cutoff($olderThanDays);

        if ($cutoff === null) {
            return ModerationPurgeOutcome::RetentionDisabled;
        }

        return DB::transaction(function () use ($commentId, $cutoff, $dryRun): ModerationPurgeOutcome {
            // Unlocked pre-read for the parent id; authoritative locks in
            // Post -> Comment order (post_id is immutable on comments).
            $preview = Comment::withTrashed()->find($commentId);

            if ($preview === null) {
                return ModerationPurgeOutcome::AlreadyGone;
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
                return ModerationPurgeOutcome::AlreadyGone;
            }

            if (
                $comment->status !== CommentStatus::Hidden
                || $comment->moderation_removed_at === null
            ) {
                return ModerationPurgeOutcome::InvalidState;
            }

            // A hidden/finalized or author-retained parent owns the whole
            // graph at post level; standalone comment cleanup steps aside.
            if ($post === null || $post->trashed() || $post->status === PostStatus::Hidden) {
                return ModerationPurgeOutcome::ParentPostHold;
            }

            if ($comment->moderation_removed_at->gt($cutoff)) {
                return ModerationPurgeOutcome::NotExpired;
            }

            $hasChildren = Comment::withTrashed()
                ->where('parent_id', $comment->id)
                ->exists();

            if ($hasChildren) {
                return ModerationPurgeOutcome::StructuralAnchor;
            }

            if ($this->openReports->onComment($comment->id, lock: ! $dryRun)) {
                return ModerationPurgeOutcome::ModerationHold;
            }

            if ($dryRun) {
                return ModerationPurgeOutcome::WouldPurge;
            }

            $this->commentDeletion->deleteLeaf($comment);

            return ModerationPurgeOutcome::Purged;
        });
    }

    /**
     * null = retention disabled and no explicit override: nothing may be
     * purged. An explicit negative override is a caller bug and throws.
     */
    private function cutoff(?int $olderThanDays): ?Carbon
    {
        $days = $olderThanDays ?? ModerationContentRetention::days();

        if ($days === null) {
            return null;
        }

        if ($days < 0) {
            throw new InvalidArgumentException("olderThanDays must not be negative, got [{$days}].");
        }

        return now()->subDays($days);
    }
}
