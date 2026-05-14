<?php

use App\Enums\VoteType;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;

it('allows post to have many post votes', function () {
    $postAuthor = User::factory()->create();
    $voterA = User::factory()->create();
    $voterB = User::factory()->create();

    $post = Post::create([
        'user_id' => $postAuthor->id,
        'title' => 'Test dish',
    ]);

    $voteA = PostVote::create([
        'post_id' => $post->id,
        'user_id' => $voterA->id,
        'type' => VoteType::Up->value,
    ]);

    $voteB = PostVote::create([
        'post_id' => $post->id,
        'user_id' => $voterB->id,
        'type' => VoteType::Down->value,
    ]);

    $ids = $post->postVotes()->pluck('id')->all();

    expect($ids)->toHaveCount(2)
        ->toContain($voteA->id)
        ->toContain($voteB->id);
});
