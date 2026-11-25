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

/*
 * The viewer's language first: comments in it, and the viewer's own whatever language they were read as, ahead of
 * the rest — each group in the chosen order; replies stay in theirs.
 */

it('puts comments in the viewer\'s language first, each group in the chosen order', function () {
    $post = Post::factory()->published()->create();
    $english = Comment::factory()->for($post)->create(['language' => 'en', 'upvotes_count' => 30, 'created_at' => now()->subMinutes(5)]);
    $russian = Comment::factory()->for($post)->create(['language' => 'ru', 'upvotes_count' => 2, 'created_at' => now()->subMinutes(4)]);
    $betterRussian = Comment::factory()->for($post)->create(['language' => 'ru', 'upvotes_count' => 9, 'created_at' => now()->subMinutes(3)]);
    $emoji = Comment::factory()->for($post)->create(['language' => null, 'upvotes_count' => 50, 'created_at' => now()->subMinutes(2)]);

    expect(app(CommentListQuery::class)->get($post->id, 'top', 10, 'ru')->pluck('id')->all())
        ->toBe([$betterRussian->id, $russian->id, $emoji->id, $english->id])
        ->and(app(CommentListQuery::class)->get($post->id, 'newest', 10, 'ru')->pluck('id')->all())
        ->toBe([$betterRussian->id, $russian->id, $emoji->id, $english->id])
        ->and(app(CommentListQuery::class)->get($post->id, 'top', 10)->pluck('id')->all())
        ->toBe([$emoji->id, $english->id, $betterRussian->id, $russian->id]);
});

it('puts the viewer\'s own comment first whatever language it was read as', function () {
    $post = Post::factory()->published()->create();
    $russian = Comment::factory()->for($post)->create(['language' => 'ru', 'created_at' => now()->subMinutes(5)]);
    $own = Comment::factory()->for($post)->create(['language' => 'bg', 'created_at' => now()->subMinutes(9)]);
    $other = Comment::factory()->for($post)->create(['language' => 'de', 'created_at' => now()]);

    expect(app(CommentListQuery::class)->get($post->id, 'newest', 10, 'ru', $own->user_id)->pluck('id')->all())
        ->toBe([$russian->id, $own->id, $other->id]);
});
