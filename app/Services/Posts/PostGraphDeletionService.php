<?php

namespace App\Services\Posts;

use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\Post;
use App\Models\PostAuthorAnswer;
use App\Models\PostSave;
use App\Models\PostVote;
use App\Models\RatingVote;
use App\Models\Report;
use App\Services\Media\MediaLifecycleService;
use RuntimeException;

/**
 * The single physical deletion boundary for a post and its graph
 * (docs/architecture/post-lifecycle.md). Purely mechanical: it decides
 * NOTHING about eligibility — retention windows, moderation holds and
 * state validation belong to the semantic callers
 * (PostRetentionPurgeService for author retention,
 * ModerationContentPurgeService for finalized moderation removals).
 *
 * Must run inside the caller's transaction with the post row already
 * locked. Explicit FK-safe order (PR-C RESTRICT graph, leaves first):
 * comment votes → comment-targeted reports → comments strictly
 * leaves-first (depth-agnostic) →
 * post votes / rating votes / saves / author answers / tag pivot →
 * post-targeted reports → the post row → DB-only media release (shared
 * assets stay active, final references soft-delete; physical files always
 * wait for the media grace). Moderation logs are audit history and are
 * deliberately kept.
 */
final class PostGraphDeletionService
{
    public function __construct(
        private readonly MediaLifecycleService $mediaLifecycle,
    ) {}

    public function deleteGraph(Post $post): void
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

            $this->deleteCommentsLeavesFirst($post);
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
     * Strictly leaves-first comment deletion, agnostic of actual tree
     * depth. The public product supports exactly one reply level, but this
     * is the final physical deletion boundary and the self-referencing
     * RESTRICT FK only guarantees referential integrity — not depth:
     * malformed/legacy rows (old code, direct SQL, imports, maintenance)
     * could nest arbitrarily, and they must never make a post permanently
     * unpurgeable. Each pass bulk-deletes every current leaf (a comment of
     * this post with no child row anywhere, trashed included), so the
     * number of iterations equals the tree depth — one pass for the
     * supported shape, never per-row queries.
     *
     * Fail-safe: rows remaining with no deletable leaf means a corrupted
     * parent cycle. That is not repairable data — fail closed with an
     * exception so the surrounding transaction rolls back the whole purge.
     */
    private function deleteCommentsLeavesFirst(Post $post): void
    {
        while (true) {
            $remaining = Comment::withTrashed()
                ->where('post_id', $post->id)
                ->count();

            if ($remaining === 0) {
                return;
            }

            // A leaf is a comment no other row (trashed included, any post)
            // references as parent. The subquery filters out NULLs, so
            // NOT IN stays NULL-safe on every supported engine.
            $leafIds = Comment::withTrashed()
                ->where('post_id', $post->id)
                ->whereNotIn('id', Comment::withTrashed()
                    ->whereNotNull('parent_id')
                    ->select('parent_id'))
                ->pluck('id');

            if ($leafIds->isEmpty()) {
                // No content/PII in the message: ids only.
                throw new RuntimeException(sprintf(
                    'Cannot purge comment graph of post [%d]: %d rows remain but none is a leaf (corrupted parent cycle?). Rolling back.',
                    $post->id,
                    $remaining,
                ));
            }

            foreach ($leafIds->chunk(500) as $chunk) {
                Comment::withTrashed()->whereIn('id', $chunk)->forceDelete();
            }
        }
    }
}
