<?php

use App\Actions\Counters\RecalculatePostCountersAction;
use App\Enums\VoteType;
use App\Models\Post;
use App\Models\PostVote;

it('recalculates upvote counter from post votes', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 99,
        'downvotes_count' => 0,
    ]);

    PostVote::factory()->for($post)->create([
        'type' => VoteType::Up,
    ]);

    $snapshot = app(RecalculatePostCountersAction::class)->handle($post);

    expect($post->fresh()->upvotes_count)->toBe(1);
    expect($snapshot->upvotes)->toBe(1);
});

it('repairs negative upvote counter', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => -5,
    ]);

    app(RecalculatePostCountersAction::class)->handle($post);

    expect($post->fresh()->upvotes_count)->toBe(0);
});

it('recalculates downvote counter from post votes', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 0,
        'downvotes_count' => 99,
    ]);

    PostVote::factory()->for($post)->create([
        'type' => VoteType::Down,
    ]);

    $snapshot = app(RecalculatePostCountersAction::class)->handle($post);

    expect($post->fresh()->downvotes_count)->toBe(1);
    expect($snapshot->downvotes)->toBe(1);
});

it('counts upvotes and downvotes independently', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 50,
        'downvotes_count' => 50,
    ]);

    PostVote::factory()->for($post)->create(['type' => VoteType::Up]);
    PostVote::factory()->for($post)->create(['type' => VoteType::Down]);

    $snapshot = app(RecalculatePostCountersAction::class)->handle($post);

    expect($post->fresh()->upvotes_count)->toBe(1);
    expect($post->fresh()->downvotes_count)->toBe(1);
    expect($snapshot->upvotes)->toBe(1);
    expect($snapshot->downvotes)->toBe(1);
});
