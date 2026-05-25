<?php

use App\Actions\Votes\VotePostAction;
use App\Enums\VoteType;
use App\Exceptions\Votes\CannotVoteException;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;

it('allows user to upvote a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    app(VotePostAction::class)->handle($user, $post, VoteType::Up);

    $this->assertDatabaseHas('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Up->value,
    ]);

    $post->refresh();
    expect($post->upvotes_count)->toBe(1);
    expect($post->downvotes_count)->toBe(0);
});

it('allows user to downvote a published post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    app(VotePostAction::class)->handle($user, $post, VoteType::Down);

    $this->assertDatabaseHas('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Down->value,
    ]);

    $post->refresh();
    expect($post->upvotes_count)->toBe(0);
    expect($post->downvotes_count)->toBe(1);
});

it('toggles upvote off when clicked again', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VotePostAction::class)->handle($user, $post, VoteType::Up);
    app(VotePostAction::class)->handle($user, $post->fresh(), VoteType::Up);

    $this->assertDatabaseMissing('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    expect($post->fresh()->upvotes_count)->toBe(0);
    expect($post->fresh()->downvotes_count)->toBe(0);
});

it('toggles downvote off when clicked again', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VotePostAction::class)->handle($user, $post, VoteType::Down);
    app(VotePostAction::class)->handle($user, $post->fresh(), VoteType::Down);

    $this->assertDatabaseMissing('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
    ]);

    expect($post->fresh()->upvotes_count)->toBe(0);
    expect($post->fresh()->downvotes_count)->toBe(0);
});

it('replaces upvote with downvote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VotePostAction::class)->handle($user, $post, VoteType::Up);
    app(VotePostAction::class)->handle($user, $post->fresh(), VoteType::Down);

    $this->assertDatabaseHas('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Down->value,
    ]);

    expect(PostVote::query()->count())->toBe(1);
    expect($post->fresh()->upvotes_count)->toBe(0);
    expect($post->fresh()->downvotes_count)->toBe(1);
});

it('replaces downvote with upvote', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();

    app(VotePostAction::class)->handle($user, $post, VoteType::Down);
    app(VotePostAction::class)->handle($user, $post->fresh(), VoteType::Up);

    $this->assertDatabaseHas('post_votes', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'type' => VoteType::Up->value,
    ]);

    expect(PostVote::query()->count())->toBe(1);
    expect($post->fresh()->upvotes_count)->toBe(1);
    expect($post->fresh()->downvotes_count)->toBe(0);
});

it('does not allow guest to vote', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    try {
        app(VotePostAction::class)->handle(null, $post, VoteType::Up);
        $this->fail('Expected CannotVoteException was not thrown.');
    } catch (CannotVoteException $e) {
        expect(PostVote::query()->count())->toBe(0);
        expect($post->fresh()->upvotes_count)->toBe(0);
    }
});

it('does not allow banned user to vote', function () {
    $user = User::factory()->banned()->create();
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    try {
        app(VotePostAction::class)->handle($user, $post, VoteType::Up);
        $this->fail('Expected CannotVoteException was not thrown.');
    } catch (CannotVoteException $e) {
        expect(PostVote::query()->count())->toBe(0);
        expect($post->fresh()->upvotes_count)->toBe(0);
    }
});

it('does not allow users to vote on their own posts', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->for($user)->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    try {
        app(VotePostAction::class)->handle($user, $post, VoteType::Up);
        $this->fail('Expected CannotVoteException was not thrown.');
    } catch (CannotVoteException $e) {
        expect($e->getMessage())->toBe('You cannot vote on your own post.');
        expect(PostVote::query()->count())->toBe(0);
        expect($post->fresh()->upvotes_count)->toBe(0);
    }
});

it('does not allow voting on non-published posts', function (string $state) {
    $user = User::factory()->create();
    $post = Post::factory()->{$state}()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 0,
    ]);

    try {
        app(VotePostAction::class)->handle($user, $post, VoteType::Up);
        $this->fail('Expected CannotVoteException was not thrown.');
    } catch (CannotVoteException $e) {
        expect(PostVote::query()->count())->toBe(0);
        expect($post->fresh()->upvotes_count)->toBe(0);
    }
})->with(['hidden', 'pending', 'rejected']);
