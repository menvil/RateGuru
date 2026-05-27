<?php

use App\Actions\Votes\VoteOriginAction;
use App\Enums\OriginType;
use App\Exceptions\Votes\CannotVoteOriginException;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\User;

it('allows user to vote homemade on a published post', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade);

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Homemade->value,
    ]);

    expect($post->fresh()->homemade_votes_count)->toBe(1);
    expect($post->fresh()->restaurant_votes_count)->toBe(0);
});

it('allows user to vote restaurant on a published post', function () {
    $user = User::factory()->create();

    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    app(VoteOriginAction::class)->handle($user, $post, OriginType::Restaurant);

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Restaurant->value,
    ]);

    expect($post->fresh()->homemade_votes_count)->toBe(0);
    expect($post->fresh()->restaurant_votes_count)->toBe(1);
});

it('does not allow user to change origin vote from homemade to restaurant', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade);
    app(VoteOriginAction::class)->handle($user, $post->fresh(), OriginType::Restaurant);

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Homemade->value,
    ]);

    expect(OriginVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);

    expect($post->fresh()->homemade_votes_count)->toBe(1);
    expect($post->fresh()->restaurant_votes_count)->toBe(0);
});

it('does not allow user to change origin vote from restaurant to homemade', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    app(VoteOriginAction::class)->handle($user, $post, OriginType::Restaurant);
    app(VoteOriginAction::class)->handle($user, $post->fresh(), OriginType::Homemade);

    expect(OriginVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);

    $this->assertDatabaseHas('origin_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'origin' => OriginType::Restaurant->value,
    ]);

    expect($post->fresh()->restaurant_votes_count)->toBe(1);
    expect($post->fresh()->homemade_votes_count)->toBe(0);
});

// Product decision (Phase 14): a repeated click on the already-selected
// origin keeps it selected — it is a no-op, not a toggle/clear.
it('keeps same homemade origin vote selected when clicked again', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade);
    app(VoteOriginAction::class)->handle($user, $post->fresh(), OriginType::Homemade);

    expect(OriginVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);

    expect($post->fresh()->homemade_votes_count)->toBe(1);
    expect($post->fresh()->restaurant_votes_count)->toBe(0);
});

it('keeps same restaurant origin vote selected when clicked again', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    app(VoteOriginAction::class)->handle($user, $post, OriginType::Restaurant);
    app(VoteOriginAction::class)->handle($user, $post->fresh(), OriginType::Restaurant);

    expect(OriginVote::query()
        ->where('user_id', $user->id)
        ->where('post_id', $post->id)
        ->count()
    )->toBe(1);

    expect($post->fresh()->restaurant_votes_count)->toBe(1);
    expect($post->fresh()->homemade_votes_count)->toBe(0);
});

it('does not allow guest to vote origin', function () {
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    try {
        app(VoteOriginAction::class)->handle(null, $post, OriginType::Homemade);
        $this->fail('Expected CannotVoteOriginException was not thrown.');
    } catch (CannotVoteOriginException $e) {
        // expected
    }

    $this->assertDatabaseMissing('origin_votes', [
        'post_id' => $post->id,
    ]);
    expect($post->fresh()->homemade_votes_count)->toBe(0);
    expect($post->fresh()->restaurant_votes_count)->toBe(0);
});

it('does not allow a blocked user to vote origin', function () {
    $user = User::factory()->banned()->create();
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    expect(fn () => app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade))
        ->toThrow(CannotVoteOriginException::class);

    $this->assertDatabaseMissing('origin_votes', [
        'post_id' => $post->id,
        'user_id' => $user->id,
    ]);
    expect($post->fresh()->homemade_votes_count)->toBe(0);
    expect($post->fresh()->restaurant_votes_count)->toBe(0);
});

it('does not allow an invalid origin value', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    expect(fn () => app(VoteOriginAction::class)->handle($user, $post, OriginType::Unknown))
        ->toThrow(CannotVoteOriginException::class);

    $this->assertDatabaseMissing('origin_votes', [
        'post_id' => $post->id,
        'user_id' => $user->id,
    ]);
    expect($post->fresh()->homemade_votes_count)->toBe(0);
    expect($post->fresh()->restaurant_votes_count)->toBe(0);
});

it('does not allow origin vote on hidden post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->hidden()->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    try {
        app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade);
        $this->fail('Expected CannotVoteOriginException was not thrown.');
    } catch (CannotVoteOriginException $e) {
        // expected
    }

    $this->assertDatabaseMissing('origin_votes', [
        'post_id' => $post->id,
        'user_id' => $user->id,
    ]);
    expect($post->fresh()->homemade_votes_count)->toBe(0);
});

it('does not allow origin vote on pending post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->pending()->create();

    expect(fn () => app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade))
        ->toThrow(CannotVoteOriginException::class);

    $this->assertDatabaseMissing('origin_votes', [
        'post_id' => $post->id,
        'user_id' => $user->id,
    ]);
});

it('does not allow origin vote on rejected post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->rejected()->create();

    expect(fn () => app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade))
        ->toThrow(CannotVoteOriginException::class);

    $this->assertDatabaseMissing('origin_votes', [
        'post_id' => $post->id,
        'user_id' => $user->id,
    ]);
});

it('does not allow origin voting on own post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->for($user)->create([
        'homemade_votes_count' => 0,
        'restaurant_votes_count' => 0,
    ]);

    expect(fn () => app(VoteOriginAction::class)->handle($user, $post, OriginType::Homemade))
        ->toThrow(CannotVoteOriginException::class, 'You cannot vote on your own post.');

    $this->assertDatabaseMissing('origin_votes', [
        'post_id' => $post->id,
        'user_id' => $user->id,
    ]);
});
