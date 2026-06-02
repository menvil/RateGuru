<?php

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Queries\Feed\FeedQuery;
use Illuminate\Support\Facades\DB;

function countQueriesForFeed(callable $callback): int
{
    $count = 0;

    DB::listen(function () use (&$count): void {
        $count++;
    });

    $callback();

    return $count;
}

it('does not perform n plus one queries when accessing feed post authors', function () {
    Post::factory()
        ->count(10)
        ->published()
        ->for(User::factory(), 'user')
        ->create();

    $queryCount = countQueriesForFeed(function (): void {
        $posts = app(FeedQuery::class)->paginate(perPage: 10);

        foreach ($posts->items() as $post) {
            $post->user?->username;
        }
    });

    expect($queryCount)->toBeLessThanOrEqual(5);
});

it('does not perform n plus one queries when accessing feed post tags', function () {
    $tags = Tag::factory()->count(3)->create();

    Post::factory()
        ->count(10)
        ->published()
        ->create()
        ->each(fn (Post $post) => $post->tags()->attach($tags->random(2)));

    $queryCount = countQueriesForFeed(function (): void {
        $posts = app(FeedQuery::class)->paginate(perPage: 10);

        foreach ($posts->items() as $post) {
            $post->tags->pluck('slug')->all();
        }
    });

    expect($queryCount)->toBeLessThanOrEqual(6);
});

it('provides vote count attributes for feed posts', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 3,
        'downvotes_count' => 1,
        'comments_count' => 2,
    ]);

    $posts = app(FeedQuery::class)->paginate();

    $first = collect($posts->items())->firstWhere('id', $post->id);

    expect($first->upvotes_count)->toBe(3);
    expect($first->downvotes_count)->toBe(1);
    expect($first->comments_count)->toBe(2);
});

it('does not load full vote relations for feed counts', function () {
    Post::factory()->published()->create();

    $posts = app(FeedQuery::class)->paginate();

    $first = $posts->items()[0];

    expect($first->relationLoaded('postVotes'))->toBeFalse();
    expect($first->relationLoaded('originVotes'))->toBeFalse();
    expect($first->relationLoaded('cuisineVotes'))->toBeFalse();
});
