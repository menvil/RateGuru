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
        ->and($query->countVisibleTopLevel($post->id))->toBe(1);
});
