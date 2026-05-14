<?php

use App\Enums\CuisineType;
use App\Models\CuisineVote;
use App\Models\Post;
use App\Models\User;

it('allows post to have many cuisine votes', function () {
    $postAuthor = User::factory()->create();
    $voter = User::factory()->create();

    $post = Post::create([
        'user_id' => $postAuthor->id,
        'title' => 'Test dish',
    ]);

    $vote = CuisineVote::create([
        'post_id' => $post->id,
        'user_id' => $voter->id,
        'cuisine' => CuisineType::Italian->value,
    ]);

    expect($post->cuisineVotes()->first()->id)->toBe($vote->id);
});
