<?php

use App\Actions\Posts\DeletePostAction;
use App\Enums\PostPurgeOutcome;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Posts\PostRetentionPurgeService;

/*
 * Physical comment-graph deletion is deliberately depth-agnostic: the
 * public product supports exactly one reply level, but the self FK only
 * guarantees referential integrity, not depth — malformed/legacy rows
 * must never make a post permanently unpurgeable, and a corrupted parent
 * cycle must fail closed instead of looping. Purges run through the real
 * sanctioned boundary (author retention -> PostGraphDeletionService).
 */

beforeEach(function () {
    config(['posts.author_delete_retention_days' => 30]);
});

function purgeablePostWithComments(callable $buildComments): Post
{
    $owner = User::factory()->create();
    $post = Post::factory()->published()->for($owner)->create();

    $buildComments($post);

    app(DeletePostAction::class)->handle($owner, $post);
    Post::withTrashed()->whereKey($post->id)->update(['deleted_at' => now()->subDays(40)]);

    return $post;
}

it('purges the supported one-level shape exactly as before', function () {
    $post = purgeablePostWithComments(function (Post $post): void {
        $root = Comment::factory()->create(['post_id' => $post->id]);
        Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $root->id]);
    });

    expect(app(PostRetentionPurgeService::class)->purge($post->id))->toBe(PostPurgeOutcome::Purged);

    expect(Post::withTrashed()->find($post->id))->toBeNull()
        ->and(Comment::withTrashed()->where('post_id', $post->id)->count())->toBe(0);
});

it('purges a malformed deeply nested chain leaves-first without FK violations', function () {
    // A -> B -> C -> D, built directly (bypasses reply-depth validation —
    // exactly the malformed/legacy shape the boundary must survive).
    $post = purgeablePostWithComments(function (Post $post): void {
        $a = Comment::factory()->create(['post_id' => $post->id]);
        $b = Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $a->id]);
        $c = Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $b->id]);
        Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $c->id]);
    });

    expect(app(PostRetentionPurgeService::class)->purge($post->id))->toBe(PostPurgeOutcome::Purged);

    expect(Post::withTrashed()->find($post->id))->toBeNull()
        ->and(Comment::withTrashed()->where('post_id', $post->id)->count())->toBe(0);
});

it('purges a mixed multi-root tree with uneven depths', function () {
    // A(B(C), D) and E(F(G)) plus a lone leaf.
    $post = purgeablePostWithComments(function (Post $post): void {
        $a = Comment::factory()->create(['post_id' => $post->id]);
        $b = Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $a->id]);
        Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $b->id]);
        Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $a->id]);

        $e = Comment::factory()->create(['post_id' => $post->id]);
        $f = Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $e->id]);
        Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $f->id]);

        Comment::factory()->create(['post_id' => $post->id]);
    });

    expect(app(PostRetentionPurgeService::class)->purge($post->id))->toBe(PostPurgeOutcome::Purged);

    expect(Post::withTrashed()->find($post->id))->toBeNull()
        ->and(Comment::withTrashed()->where('post_id', $post->id)->count())->toBe(0);
});

it('fails closed on a corrupted parent cycle and rolls the whole purge back', function () {
    $post = purgeablePostWithComments(function (Post $post): void {
        // Legit leaf that would be deleted in pass one…
        Comment::factory()->create(['post_id' => $post->id]);

        // …plus a corrupted A <-> B cycle: each row is the other's child,
        // so neither can ever become a leaf.
        $a = Comment::factory()->create(['post_id' => $post->id]);
        $b = Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $a->id]);
        Comment::query()->whereKey($a->id)->update(['parent_id' => $b->id]);

        CommentVote::create(['comment_id' => $a->id, 'user_id' => User::factory()->create()->id, 'type' => 'up']);
    });

    $asset = MediaAsset::factory()->postImage()->create();
    Post::withTrashed()->whereKey($post->id)->update(['image_asset_id' => $asset->id]);

    expect(fn () => app(PostRetentionPurgeService::class)->purge($post->id))
        ->toThrow(RuntimeException::class, 'none is a leaf');

    // Nothing was partially destroyed: the pass-one leaf, the cycle rows,
    // the votes and the media reference all survived the rollback.
    expect(Post::withTrashed()->find($post->id))->not->toBeNull()
        ->and(Comment::withTrashed()->where('post_id', $post->id)->count())->toBe(3)
        ->and(CommentVote::query()->count())->toBe(1)
        ->and(MediaAsset::withTrashed()->findOrFail($asset->id)->trashed())->toBeFalse();
});
