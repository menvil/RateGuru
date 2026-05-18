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
