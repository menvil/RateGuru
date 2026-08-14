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
 * comment votes → comment-targeted reports → replies → root comments →
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
}
