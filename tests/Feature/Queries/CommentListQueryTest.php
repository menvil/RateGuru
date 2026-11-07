<?php

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Queries\Comments\CommentListQuery;

it('orders top-level comments by score with a stable timestamp and id fallback', function () {
    $post = Post::factory()->published()->create();
    $createdAt = now();

    $lowerScore = Comment::factory()->for($post)->create([
        'status' => CommentStatus::Visible,
        'upvotes_count' => 5,
        'downvotes_count' => 2,
        'created_at' => $createdAt,
    ]);
    $firstHigherScore = Comment::factory()->for($post)->create([
        'status' => CommentStatus::Visible,
        'upvotes_count' => 5,
        'downvotes_count' => 1,
        'created_at' => $createdAt,
    ]);
    $secondHigherScore = Comment::factory()->for($post)->create([
        'status' => CommentStatus::Visible,
        'upvotes_count' => 5,
        'downvotes_count' => 1,
        'created_at' => $createdAt,
    ]);

    $comments = app(CommentListQuery::class)->get($post->id, 'top', 5);

    expect($comments->modelKeys())->toBe([
        $secondHigherScore->id,
        $firstHigherScore->id,
        $lowerScore->id,
    ]);
});

it('orders top-level comments by total engagement', function () {
    $post = Post::factory()->published()->create();
    $quiet = Comment::factory()->for($post)->create([
        'status' => CommentStatus::Visible,
        'upvotes_count' => 2,
        'downvotes_count' => 0,
    ]);
    $active = Comment::factory()->for($post)->create([
        'status' => CommentStatus::Visible,
        'upvotes_count' => 2,
        'downvotes_count' => 3,
    ]);

    $comments = app(CommentListQuery::class)->get($post->id, 'hot', 5);

    expect($comments->modelKeys())->toBe([$active->id, $quiet->id]);
});

it('counts visible comments without loading hidden rows', function () {
    $post = Post::factory()->published()->create();
    $parent = Comment::factory()->for($post)->create(['status' => CommentStatus::Visible]);
    Comment::factory()->for($post)->create([
        'parent_id' => $parent->id,
        'status' => CommentStatus::Visible,
    ]);
    Comment::factory()->for($post)->create(['status' => CommentStatus::Hidden]);

    $query = app(CommentListQuery::class);

    expect($query->countVisible($post->id))->toBe(2)
        ->and($query->countRenderableTopLevel($post->id))->toBe(1);
});

it('lists no thread for a post that is not public, whatever id is asked for', function (Post $post) {
    $root = Comment::factory()->for($post)->create(['status' => CommentStatus::Visible]);
    Comment::factory()->for($post)->create(['status' => CommentStatus::Visible, 'parent_id' => $root->id]);

    $query = app(CommentListQuery::class);

    expect($query->get($post->id, 'top', 5))->toBeEmpty()
        ->and($query->countVisible($post->id))->toBe(0)
        ->and($query->countRenderableTopLevel($post->id))->toBe(0);
})->with([
    'hidden by a moderator' => fn (): Post => Post::factory()->hidden()->create(),
    'waiting for review' => fn (): Post => Post::factory()->pending()->create(),
    'deleted by its author' => function (): Post {
        $post = Post::factory()->published()->create();
        $post->delete();

        return $post;
    },
]);

it('lists at most a bounded page of a thread, however many are asked for', function () {
    $post = Post::factory()->published()->create();
    Comment::factory()->for($post)->count(CommentListQuery::MOST_ROOTS + 1)->create(['status' => CommentStatus::Visible]);

    expect(app(CommentListQuery::class)->get($post->id, 'newest', 100000))->toHaveCount(CommentListQuery::MOST_ROOTS);
});
