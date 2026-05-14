<?php

use App\Enums\OriginType;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\User;

it('allows post to have many origin votes', function () {
    $postAuthor = User::factory()->create();
    $voter = User::factory()->create();

    $post = Post::create([
        'user_id' => $postAuthor->id,
        'title' => 'Test dish',
    ]);

    $vote = OriginVote::create([
        'post_id' => $post->id,
        'user_id' => $voter->id,
        'origin' => OriginType::Homemade->value,
    ]);

    expect($post->originVotes()->first()->id)->toBe($vote->id);
});
