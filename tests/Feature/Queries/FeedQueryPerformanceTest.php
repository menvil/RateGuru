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
