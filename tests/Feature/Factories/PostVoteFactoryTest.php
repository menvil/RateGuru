<?php

use App\Enums\VoteType;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;

it('can create a post vote with factory', function () {
    $vote = PostVote::factory()->create();

    expect($vote)->toBeInstanceOf(PostVote::class);
    expect($vote->exists)->toBeTrue();
    expect($vote->type)->toBeInstanceOf(VoteType::class);
    expect($vote->post)->toBeInstanceOf(Post::class);
    expect($vote->user)->toBeInstanceOf(User::class);
});
