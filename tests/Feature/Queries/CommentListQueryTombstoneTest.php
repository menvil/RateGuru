<?php

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Queries\Comments\CommentListQuery;

/*
 * §39 matrix: which top-level rows are renderable, which replies load, and
 * what the two counts mean after tombstones enter the picture.
 */

function makeRoot(Post $post, string $state): Comment
{
    $root = Comment::factory()->create([
        'post_id' => $post->id,
        'status' => $state === 'hidden' ? CommentStatus::Hidden : CommentStatus::Visible,
    ]);

    if ($state === 'deleted') {
        $root->delete();
    }

    return $root;
}

function makeReply(Post $post, Comment $root, string $state): Comment
{
    $reply = Comment::factory()->create([
        'post_id' => $post->id,
        'parent_id' => $root->id,
        'status' => $state === 'hidden' ? CommentStatus::Hidden : CommentStatus::Visible,
    ]);

    if ($state === 'deleted') {
        $reply->delete();
    }

    return $reply;
}

it('decides root renderability by the tombstone matrix', function (
    string $rootState,
    ?string $replyState,
    bool $included,
) {
    $post = Post::factory()->published()->create();
    $root = makeRoot($post, $rootState);

    if ($replyState !== null) {
        makeReply($post, $root, $replyState);
    }

    $roots = app(CommentListQuery::class)->get($post->id, 'newest', 10);

    expect($roots->pluck('id')->contains($root->id))->toBe($included)
        ->and(app(CommentListQuery::class)->countRenderableTopLevel($post->id))->toBe($included ? 1 : 0);
})->with([
    'visible root' => ['visible', null, true],
    'deleted root without replies' => ['deleted', null, false],
    'hidden root without replies' => ['hidden', null, false],
    'deleted root + live reply' => ['deleted', 'visible', true],
    'hidden root + live reply' => ['hidden', 'visible', true],
    'deleted root + deleted reply' => ['deleted', 'deleted', false],
    'hidden root + hidden reply' => ['hidden', 'hidden', false],
]);

it('never eager-loads hidden or deleted replies onto a structural root', function () {
    $post = Post::factory()->published()->create();
    $root = makeRoot($post, 'deleted');
    $live = makeReply($post, $root, 'visible');
    makeReply($post, $root, 'hidden');
    makeReply($post, $root, 'deleted');

    $roots = app(CommentListQuery::class)->get($post->id, 'newest', 10);

    expect($roots)->toHaveCount(1)
        ->and($roots->first()->replies->pluck('id')->all())->toBe([$live->id]);
});

it('keeps the public count and the renderable-root count distinct', function () {
    $post = Post::factory()->published()->create();
    $root = makeRoot($post, 'deleted');
    makeReply($post, $root, 'visible');

    $query = app(CommentListQuery::class);

    // The tombstone is not a comment publicly; the reply is. But the thread
    // still has exactly one renderable root anchoring that reply.
    expect($query->countVisible($post->id))->toBe(1)
        ->and($query->countRenderableTopLevel($post->id))->toBe(1);
});

it('pins the comments_count matrix', function (
    string $rootState,
    ?string $replyState,
    int $publicCount,
) {
    $post = Post::factory()->published()->create();
    $root = makeRoot($post, $rootState);

    if ($replyState !== null) {
        makeReply($post, $root, $replyState);
    }

    expect(app(CommentListQuery::class)->countVisible($post->id))->toBe($publicCount);
})->with([
    'visible root' => ['visible', null, 1],
    'visible root + visible reply' => ['visible', 'visible', 2],
    'deleted root + visible reply' => ['deleted', 'visible', 1],
    'hidden root + visible reply' => ['hidden', 'visible', 1],
    'deleted root only' => ['deleted', null, 0],
    'hidden root only' => ['hidden', null, 0],
]);
