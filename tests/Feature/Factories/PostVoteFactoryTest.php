<?php

use App\Enums\VoteType;
use App\Models\PostVote;

it('can create a post vote with factory', function () {
    $vote = PostVote::factory()->create();

    expect($vote)->toBeInstanceOf(PostVote::class);
    expect($vote->exists)->toBeTrue();
    expect($vote->type)->toBeInstanceOf(VoteType::class);
});
