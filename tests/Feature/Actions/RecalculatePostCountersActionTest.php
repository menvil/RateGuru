<?php

use App\Actions\Counters\RecalculatePostCountersAction;
use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\VoteType;
use App\Models\CuisineVote;
use App\Models\OriginVote;
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

it('recalculates origin vote counters from origin votes', function () {
    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 99,
        'restaurant_votes_count' => 88,
    ]);

    OriginVote::factory()->for($post)->create([
        'origin' => OriginType::Homemade,
    ]);

    OriginVote::factory()->for($post)->create([
        'origin' => OriginType::Restaurant,
    ]);

    OriginVote::factory()->for($post)->create([
        'origin' => OriginType::Restaurant,
    ]);

    $snapshot = app(RecalculatePostCountersAction::class)->handle($post);

    expect($post->fresh()->homemade_votes_count)->toBe(1);
    expect($post->fresh()->restaurant_votes_count)->toBe(2);
    expect($snapshot->homemadeVotes)->toBe(1);
    expect($snapshot->restaurantVotes)->toBe(2);
});

it('recalculates cuisine vote distribution from cuisine votes', function () {
    $post = Post::factory()->published()->create();

    CuisineVote::factory()->for($post)->create([
        'cuisine' => CuisineType::Italian,
    ]);

    CuisineVote::factory()->for($post)->create([
        'cuisine' => CuisineType::Italian,
    ]);

    CuisineVote::factory()->for($post)->create([
        'cuisine' => CuisineType::Asian,
    ]);

    $snapshot = app(RecalculatePostCountersAction::class)->handle($post);

    expect($snapshot->cuisineVotes)->toMatchArray([
        CuisineType::Italian->value => 2,
        CuisineType::Asian->value => 1,
        CuisineType::American->value => 0,
        CuisineType::Mexican->value => 0,
        CuisineType::Other->value => 0,
    ]);
});

it('does not require persisted cuisine counter columns on posts', function () {
    $post = Post::factory()->published()->create();

    $snapshot = app(RecalculatePostCountersAction::class)->handle($post);

    expect($snapshot->cuisineVotes)->toBeArray();
});

it('recalculates counters after post vote instead of incrementing stale value', function () {
    $user = \App\Models\User::factory()->create();

    $post = Post::factory()->published()->create([
        'upvotes_count' => 99,
        'downvotes_count' => 88,
    ]);

    app(\App\Actions\Votes\VotePostAction::class)->handle($user, $post, VoteType::Up);

    expect($post->fresh()->upvotes_count)->toBe(1);
    expect($post->fresh()->downvotes_count)->toBe(0);
});

it('recalculates counters after origin vote instead of incrementing stale value', function () {
    $user = \App\Models\User::factory()->create();

    $post = Post::factory()->published()->create([
        'homemade_votes_count' => 99,
        'restaurant_votes_count' => 88,
    ]);

    app(\App\Actions\Votes\VoteOriginAction::class)->handle($user, $post, OriginType::Homemade);

    expect($post->fresh()->homemade_votes_count)->toBe(1);
    expect($post->fresh()->restaurant_votes_count)->toBe(0);
});
