<?php

use App\Enums\OriginType;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\User;

it('allows post to have many origin votes', function () {
    $postAuthor = User::factory()->create();
    $voterA = User::factory()->create();
    $voterB = User::factory()->create();

    $post = Post::create([
        'user_id' => $postAuthor->id,
        'title' => 'Test dish',
    ]);

    $voteA = OriginVote::create([
        'post_id' => $post->id,
        'user_id' => $voterA->id,
        'origin' => OriginType::Homemade->value,
    ]);

    $voteB = OriginVote::create([
        'post_id' => $post->id,
        'user_id' => $voterB->id,
        'origin' => OriginType::Restaurant->value,
    ]);

    $ids = $post->originVotes()->pluck('id')->all();

    expect($ids)->toHaveCount(2)
        ->toContain($voteA->id)
        ->toContain($voteB->id);
});
