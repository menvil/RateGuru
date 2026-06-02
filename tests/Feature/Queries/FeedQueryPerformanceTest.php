<?php

use App\Models\Post;
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
