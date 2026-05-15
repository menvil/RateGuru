<?php

use App\Enums\OriginType;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\User;

it('can create an origin vote with factory', function () {
    $vote = OriginVote::factory()->create();

    expect($vote)->toBeInstanceOf(OriginVote::class);
    expect($vote->exists)->toBeTrue();
    expect($vote->origin)->toBeInstanceOf(OriginType::class);
    expect($vote->post)->toBeInstanceOf(Post::class);
    expect($vote->user)->toBeInstanceOf(User::class);
});

it('does not create an origin vote with unknown origin by default', function () {
    $vote = OriginVote::factory()->create();

    expect($vote->origin)->not->toBe(OriginType::Unknown);
});
