<?php

use App\Actions\Votes\VotePostAction;
use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;

it('blocks excessive post votes from same user', function () {
    config()->set('rate_limits.vote.max_attempts', 2);
    config()->set('rate_limits.vote.decay_seconds', 60);

    $user = User::factory()->create();

    $first = Post::factory()->published()->create();
    $second = Post::factory()->published()->create();
    $third = Post::factory()->published()->create();

    app(VotePostAction::class)->handle($user, $first, VoteType::Up);
    app(VotePostAction::class)->handle($user, $second, VoteType::Up);

    app(VotePostAction::class)->handle($user, $third, VoteType::Up);
})->throws(CannotVoteException::class, 'You are voting too quickly. Please try again later.');

it('does not mutate vote counters when vote rate limit is exceeded', function () {
    config()->set('rate_limits.vote.max_attempts', 1);
    config()->set('rate_limits.vote.decay_seconds', 60);

    $user = User::factory()->create();

    $first = Post::factory()->published()->create(['upvotes_count' => 0]);
    $second = Post::factory()->published()->create(['upvotes_count' => 0]);

    app(VotePostAction::class)->handle($user, $first, VoteType::Up);

    try {
        app(VotePostAction::class)->handle($user, $second, VoteType::Up);
    } catch (CannotVoteException) {
        // Expected.
    }

    expect(PostVote::query()->where('post_id', $second->id)->count())->toBe(0);
    expect($first->fresh()->upvotes_count)->toBe(1);
    expect($second->fresh()->upvotes_count)->toBe(0);
});

it('does not block another user when first user hits vote limit', function () {
    config()->set('rate_limits.vote.max_attempts', 1);
    config()->set('rate_limits.vote.decay_seconds', 60);

    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $first = Post::factory()->published()->create();
    $second = Post::factory()->published()->create();

    app(VotePostAction::class)->handle($firstUser, $first, VoteType::Up);

    try {
        app(VotePostAction::class)->handle($firstUser, $second, VoteType::Up);
    } catch (CannotVoteException) {
        // Expected.
    }

    app(VotePostAction::class)->handle($secondUser, $second, VoteType::Up);

    expect($second->fresh()->upvotes_count)->toBe(1);
});
