<?php

use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Queries\Rating\RatingVoteCountsQuery;
use Illuminate\Support\Facades\DB;

it('groups rating vote counts for all requested posts and groups in one query', function () {
    $posts = Post::factory()->count(2)->published()->create();
    $group = RatingGroup::factory()->create();
    $firstOption = RatingOption::factory()->for($group, 'group')->create();
    $secondOption = RatingOption::factory()->for($group, 'group')->create();

    RatingVote::factory()->count(2)->for($posts[0])->for($group, 'group')->for($firstOption, 'option')->create();
    RatingVote::factory()->for($posts[1])->for($group, 'group')->for($secondOption, 'option')->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $counts = app(RatingVoteCountsQuery::class)->forPostsAndGroups(
        $posts->modelKeys(),
        [$group->id],
    );

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and((int) $counts[$posts[0]->id][$group->id][0]->aggregate)->toBe(2)
        ->and((int) $counts[$posts[1]->id][$group->id][0]->aggregate)->toBe(1);
});

it('does not query the database for an empty boundary', function () {
    DB::flushQueryLog();
    DB::enableQueryLog();

    $counts = app(RatingVoteCountsQuery::class)->forPostsAndGroups([], []);

    expect($counts)->toBeEmpty()
        ->and(DB::getQueryLog())->toBeEmpty();
});
