<?php

namespace App\Services\Posts;

use App\Enums\PostPurgeOutcome;
use App\Enums\PostStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Support\Moderation\OpenReportHold;
use App\Support\Posts\PostRetention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single sanctioned boundary allowed to permanently remove an
 * author-deleted post and its graph (docs/architecture/post-lifecycle.md).
 * The RESTRICT FKs make any other physical deletion path fail by design;
 * this service deletes the child graph explicitly, bottom-up, in one
 * transaction, then releases the post's media asset reference — DB-only,
 * physical files stay for the media grace period.
 *
 * Lock order matches every other post writer: Post row first, then the
 * post's Comment rows, then child data. Comment rows are locked before any
 * child deletion so in-flight comment votes/reports serialize behind the
 * purge instead of interleaving with it.
 */
final class PostRetentionPurgeService
{
    public function __construct(
        private readonly PostGraphDeletionService $graphDeletion,
        private readonly OpenReportHold $openReports,
    ) {}

    /**
     * Rows that look purge-eligible. Never authoritative: purge() re-reads
     * each row under lock and repeats every check before touching anything.
     */
    public function candidates(?int $olderThanDays = null): Builder
    {
        return Post::onlyTrashed()
            ->where('status', PostStatus::Deleted)
            ->where('deleted_at', '<=', $this->cutoff($olderThanDays));
    }

    public function purge(int $postId, ?int $olderThanDays = null, bool $dryRun = false): PostPurgeOutcome
    {
        return DB::transaction(function () use ($postId, $olderThanDays, $dryRun): PostPurgeOutcome {
            $query = Post::withTrashed()->whereKey($postId);

            // Dry-run repeats the exact eligibility logic but takes no row
            // locks and performs no writes.
            $post = $dryRun ? $query->first() : $query->lockForUpdate()->first();

            if ($post === null) {
                return PostPurgeOutcome::AlreadyGone;
            }

            // Fail closed: only the well-formed author-deletion shape is
            // purgeable. Live posts, Hidden posts, soft-deleted rows without
            // the Deleted status, Deleted status without soft-delete, and
            // rows without a valid captured source status are all refused.
            if (
                ! $post->trashed()
                || $post->status !== PostStatus::Deleted
                || ! in_array($post->deleted_from_status, Post::AUTHOR_DELETABLE_STATUSES, true)
            ) {
                return PostPurgeOutcome::InvalidState;
            }

            if ($post->deleted_at->gt($this->cutoff($olderThanDays))) {
                return PostPurgeOutcome::NotExpired;
            }

            // Active moderation evidence holds the purge: a review flag, or
            // an open report on the post or any of its comments. Closed
            // reports never hold it forever.
            if ($post->needs_review || $this->openReports->onPostGraph($post->id, lock: ! $dryRun)) {
                return PostPurgeOutcome::ModerationHold;
            }

            if ($dryRun) {
                return PostPurgeOutcome::WouldPurge;
            }

            // Physical deletion is delegated to the single sanctioned
            // graph boundary; this service owns only author-retention
            // eligibility (docs/architecture/post-lifecycle.md).
            $this->graphDeletion->deleteGraph($post);

            return PostPurgeOutcome::Purged;
        });
    }

    private function cutoff(?int $olderThanDays): Carbon
    {
        $days = $olderThanDays ?? PostRetention::days();

        if ($days < 0) {
            throw new InvalidArgumentException("olderThanDays must not be negative, got [{$days}].");
        }

        return now()->subDays($days);
    }
}
