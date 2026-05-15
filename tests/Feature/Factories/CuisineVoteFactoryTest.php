<?php

use App\Enums\CuisineType;
use App\Models\CuisineVote;
use App\Models\Post;
use App\Models\User;

it('can create a cuisine vote with factory', function () {
    $vote = CuisineVote::factory()->create();

    expect($vote)->toBeInstanceOf(CuisineVote::class);
    expect($vote->exists)->toBeTrue();
    expect($vote->cuisine)->toBeInstanceOf(CuisineType::class);
    expect($vote->post)->toBeInstanceOf(Post::class);
    expect($vote->user)->toBeInstanceOf(User::class);
});

it('does not create a cuisine vote with unknown cuisine by default', function () {
    $vote = CuisineVote::factory()->create();

    expect($vote->cuisine)->not->toBe(CuisineType::Unknown);
});
