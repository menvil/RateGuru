<?php

use App\Enums\VoteType;
use App\Models\PostVote;

it('casts post vote type to VoteType enum', function () {
    $vote = new PostVote(['type' => VoteType::Up]);

    expect($vote->type)->toBe(VoteType::Up);
});
