<?php

use App\Enums\OriginType;
use App\Enums\VoteType;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\PostVote;

it('has recalculate post counters command', function () {
    $this->artisan('rateguru:recalculate-post-counters')
        ->assertExitCode(0);
});

it('recalculates all post counters with fallback command', function () {
    $post = Post::factory()->published()->create([
        'upvotes_count' => 99,
        'downvotes_count' => 88,
        'homemade_votes_count' => 77,
        'restaurant_votes_count' => 66,
    ]);

    PostVote::factory()->for($post)->create(['type' => VoteType::Up]);
    PostVote::factory()->for($post)->create(['type' => VoteType::Down]);

    OriginVote::factory()->for($post)->create(['origin' => OriginType::Homemade]);
    OriginVote::factory()->for($post)->create(['origin' => OriginType::Restaurant]);
    OriginVote::factory()->for($post)->create(['origin' => OriginType::Restaurant]);

    $this->artisan('rateguru:recalculate-post-counters')
        ->expectsOutput('Recalculated counters for 1 posts.')
        ->assertExitCode(0);

    $post->refresh();

    expect($post->upvotes_count)->toBe(1);
    expect($post->downvotes_count)->toBe(1);
    expect($post->homemade_votes_count)->toBe(1);
    expect($post->restaurant_votes_count)->toBe(2);
});

it('can recalculate one post by id', function () {
    $target = Post::factory()->published()->create(['upvotes_count' => 99]);
    $other = Post::factory()->published()->create(['upvotes_count' => 99]);

    PostVote::factory()->for($target)->create(['type' => VoteType::Up]);

    $this->artisan('rateguru:recalculate-post-counters', [
        '--post-id' => $target->id,
    ])->assertExitCode(0);

    expect($target->fresh()->upvotes_count)->toBe(1);
    expect($other->fresh()->upvotes_count)->toBe(99);
});
