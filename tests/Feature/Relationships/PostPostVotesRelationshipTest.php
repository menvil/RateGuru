<?php

use App\Enums\VoteType;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;

it('allows post to have many post votes', function () {
    $postAuthor = User::factory()->create();
    $voter = User::factory()->create();

    $post = Post::create([
        'user_id' => $postAuthor->id,
        'title' => 'Test dish',
    ]);

    $vote = PostVote::create([
        'post_id' => $post->id,
        'user_id' => $voter->id,
        'type' => VoteType::Up->value,
    ]);

    expect($post->postVotes()->first()->id)->toBe($vote->id);
});
