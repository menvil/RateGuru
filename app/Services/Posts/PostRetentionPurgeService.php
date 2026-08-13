<?php

namespace App\Services\Posts;

use App\Enums\PostPurgeOutcome;
use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Post;
use App\Models\PostAuthorAnswer;
use App\Models\PostSave;
use App\Models\PostVote;
use App\Models\RatingVote;
use App\Models\Report;
use App\Services\Media\MediaLifecycleService;
use App\Support\Posts\PostRetention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single sanctioned boundary allowed to permanently remove an
 * author-deleted post and its graph (docs/architecture/post-lifecycle.md).
 * PR-C RESTRICT FKs make any other physical deletion path fail by design;
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
        private readonly MediaLifecycleService $mediaLifecycle,
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

            if ($this->hasModerationHold($post)) {
                return PostPurgeOutcome::ModerationHold;
            }

            if ($dryRun) {
                return PostPurgeOutcome::WouldPurge;
            }

            $this->purgeGraph($post);

            return PostPurgeOutcome::Purged;
        });
    }

    /**
     * Active moderation evidence blocks the purge: a post flagged for
     * review, or any open report against the post or its comment graph.
     * Resolved/ignored reports never hold a purge open forever.
     */
    private function hasModerationHold(Post $post): bool
    {
        if ($post->needs_review) {
            return true;
        }

        $openPostReport = Report::query()
            ->where('status', ReportStatus::Open)
            ->where('target_type', Post::class)
            ->where('target_id', $post->id)
            ->exists();

        if ($openPostReport) {
            return true;
        }

        return Report::query()
            ->where('status', ReportStatus::Open)
            ->where('target_type', Comment::class)
            ->whereIn('target_id', Comment::withTrashed()
                ->where('post_id', $post->id)
                ->select('id'))
            ->exists();
    }

    /**
     * Explicit FK-safe deletion order (PR-C RESTRICT graph, leaves first):
     * comment votes → comment-targeted reports → replies → root comments →
     * post votes / rating votes / saves / author answers / tag pivot →
     * post-targeted reports → the post row → media release. Moderation logs
     * are audit history and are deliberately kept.
     */
    private function purgeGraph(Post $post): void
    {
        // Serialize against in-flight comment writers before sweeping their
        // child rows; whoever was waiting revalidates after our commit and
        // finds the comment gone.
        $commentIds = Comment::withTrashed()
            ->where('post_id', $post->id)
            ->lockForUpdate()
            ->pluck('id');

        if ($commentIds->isNotEmpty()) {
            CommentVote::query()->whereIn('comment_id', $commentIds)->delete();

            Report::query()
                ->where('target_type', Comment::class)
                ->whereIn('target_id', $commentIds)
                ->delete();

            // Replies before roots: the self-referencing comments FK
            // restricts deleting a parent that still has children.
            Comment::withTrashed()
                ->where('post_id', $post->id)
                ->whereNotNull('parent_id')
                ->forceDelete();

            Comment::withTrashed()
                ->where('post_id', $post->id)
                ->forceDelete();
        }

        PostVote::query()->where('post_id', $post->id)->delete();
        RatingVote::query()->where('post_id', $post->id)->delete();
        PostSave::query()->where('post_id', $post->id)->delete();
        PostAuthorAnswer::query()->where('post_id', $post->id)->delete();

        // The pivot cascades on post delete, but the purge deletes its
        // whole graph explicitly rather than relying on DB policy.
        $post->tags()->detach();

        Report::query()
            ->where('target_type', Post::class)
            ->where('target_id', $post->id)
            ->delete();

        // Read the FK attribute directly: no lazy relation load, and no
        // soft-delete scope on the asset deciding whether we see the id.
        $rawAssetId = $post->getAttribute('image_asset_id');
        $imageAssetId = $rawAssetId === null ? null : (int) $rawAssetId;

        $post->forceDelete();

        // DB-only release in the same transaction: with the last post
        // reference gone the asset soft-deletes; a still-shared asset stays
        // active. Physical deletion waits for media:purge after the grace
        // period — never here.
        if ($imageAssetId !== null) {
            $this->mediaLifecycle->releaseUnreferenced(collect([$imageAssetId]));
        }
    }

    /**
     * Fail closed on any invalid retention: PostRetention::days() rejects
     * misconfigured config values (negative, non-numeric), and an explicit
     * negative argument from a caller is rejected here — same boundary
     * contract as MediaLifecycleService::resolveGraceDays(). A destructive
     * scheduled purge must stop on bad configuration, never run early.
     */
    private function cutoff(?int $olderThanDays): Carbon
    {
        $days = $olderThanDays ?? PostRetention::days();

        if ($days < 0) {
            throw new InvalidArgumentException("olderThanDays must not be negative, got [{$days}].");
        }

        return now()->subDays($days);
    }
}
