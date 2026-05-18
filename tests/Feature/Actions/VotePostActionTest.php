<?php

use App\Actions\Votes\VotePostAction;
use App\Enums\VoteType;
use App\Models\Post;
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

    expect($post->fresh()->upvotes_count)->toBe(1);
    expect($post->fresh()->downvotes_count)->toBe(0);
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

    expect($post->fresh()->upvotes_count)->toBe(0);
    expect($post->fresh()->downvotes_count)->toBe(1);
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

    expect(\App\Models\PostVote::query()->count())->toBe(1);
    expect($post->fresh()->upvotes_count)->toBe(0);
    expect($post->fresh()->downvotes_count)->toBe(1);
});
