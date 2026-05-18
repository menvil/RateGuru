<?php

use App\Actions\Votes\VoteOriginAction;
use App\Enums\OriginType;
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
