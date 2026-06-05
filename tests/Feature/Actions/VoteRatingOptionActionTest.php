<?php

use App\Actions\Rating\VoteRatingOptionAction;
use App\Exceptions\Rating\CannotVoteForRatingOptionException;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;

it('creates a rating vote for the selected option', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create();
    $option = RatingOption::factory()->for($group, 'group')->create();

    app(VoteRatingOptionAction::class)->handle($user, $post, $option);

    $this->assertDatabaseHas('rating_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'rating_group_id' => $group->id,
        'rating_option_id' => $option->id,
    ]);
});

it('replaces a previous vote in the same rating group', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create();
    $first = RatingOption::factory()->for($group, 'group')->create();
    $second = RatingOption::factory()->for($group, 'group')->create();

    app(VoteRatingOptionAction::class)->handle($user, $post, $first);
    app(VoteRatingOptionAction::class)->handle($user, $post, $second);

    expect(RatingVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->where('rating_group_id', $group->id)
        ->count()
    )->toBe(1);

    $this->assertDatabaseHas('rating_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'rating_group_id' => $group->id,
        'rating_option_id' => $second->id,
    ]);
});

it('handles repeated first-choice votes without duplicate rows', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $option = RatingOption::factory()->create();

    app(VoteRatingOptionAction::class)->handle($user, $post, $option);
    app(VoteRatingOptionAction::class)->handle($user, $post, $option);

    expect(RatingVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->where('rating_group_id', $option->rating_group_id)
        ->count()
    )->toBe(1);
});

it('allows votes in different rating groups for the same post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $firstOption = RatingOption::factory()->create();
    $secondOption = RatingOption::factory()->create();

    app(VoteRatingOptionAction::class)->handle($user, $post, $firstOption);
    app(VoteRatingOptionAction::class)->handle($user, $post, $secondOption);

    expect(RatingVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(2);
});

it('does not allow voting for an inactive rating option', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $option = RatingOption::factory()->create(['is_active' => false]);

    expect(fn () => app(VoteRatingOptionAction::class)->handle($user, $post, $option))
        ->toThrow(CannotVoteForRatingOptionException::class);
});

it('does not allow voting in an inactive rating group', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['is_active' => false]);
    $option = RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);

    expect(fn () => app(VoteRatingOptionAction::class)->handle($user, $post, $option))
        ->toThrow(CannotVoteForRatingOptionException::class);
});

it('does not allow a blocked user to vote for a rating option', function () {
    $user = User::factory()->banned()->create();
    $post = Post::factory()->published()->create();
    $option = RatingOption::factory()->create();

    expect(fn () => app(VoteRatingOptionAction::class)->handle($user, $post, $option))
        ->toThrow(CannotVoteForRatingOptionException::class);
});

it('does not allow rating option voting on a non-public post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->hidden()->create();
    $option = RatingOption::factory()->create();

    expect(fn () => app(VoteRatingOptionAction::class)->handle($user, $post, $option))
        ->toThrow(CannotVoteForRatingOptionException::class);
});

it('does not allow rating option voting on own post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->for($user)->create();
    $option = RatingOption::factory()->create();

    expect(fn () => app(VoteRatingOptionAction::class)->handle($user, $post, $option))
        ->toThrow(CannotVoteForRatingOptionException::class, 'You cannot vote on your own post.');
});
