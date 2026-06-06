<?php

use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;
use App\Support\Rating\RatingVotingStateLoader;
use Illuminate\Support\Facades\DB;

it('preloads rating distributions and selected options for multiple posts', function () {
    $user = User::factory()->create();
    $posts = Post::factory()->count(2)->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'source']);
    $first = RatingOption::factory()->for($group, 'group')->create();
    $second = RatingOption::factory()->for($group, 'group')->create();

    RatingVote::factory()->for($user)->for($posts[0])->for($group, 'group')->for($first, 'option')->create();
    RatingVote::factory()->for($posts[1])->for($group, 'group')->for($second, 'option')->create();

    $states = app(RatingVotingStateLoader::class)->forPosts($posts, $user);

    expect($states[$posts[0]->id]['source']['selected_option_id'])->toBe($first->id)
        ->and($states[$posts[0]->id]['source']['distribution'][$first->id]['count'])->toBe(1)
        ->and($states[$posts[1]->id]['source']['selected_option_id'])->toBeNull()
        ->and($states[$posts[1]->id]['source']['distribution'][$second->id]['count'])->toBe(1);
});

it('preloads feed rating state with a fixed number of queries', function () {
    $posts = Post::factory()->count(5)->published()->create();
    $user = User::factory()->create();
    $group = RatingGroup::factory()->create(['key' => 'source']);
    RatingOption::factory()->count(2)->for($group, 'group')->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(RatingVotingStateLoader::class)->forPosts($posts, $user);

    expect(DB::getQueryLog())->toHaveCount(4);
});
