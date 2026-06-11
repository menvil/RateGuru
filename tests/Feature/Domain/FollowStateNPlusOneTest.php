<?php

use App\Models\Follow;
use App\Models\User;
use App\Support\Follows\FollowState;
use Illuminate\Support\Facades\DB;

function countQueriesForFollowState(callable $callback): int
{
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('does not perform n plus one queries for follow states', function () {
    $viewer = User::factory()->create();
    $authors = User::factory()->count(10)->create();

    Follow::factory()->create([
        'follower_id' => $viewer->id,
        'author_id' => $authors[0]->id,
    ]);

    $queryCount = countQueriesForFollowState(function () use ($viewer, $authors): void {
        app(FollowState::class)->forViewerAndAuthors($viewer, $authors);
    });

    expect($queryCount)->toBeLessThanOrEqual(2);
});
