<?php

use App\Enums\OriginType;
use App\Models\OriginVote;

it('casts origin vote origin to OriginType enum', function () {
    $vote = new OriginVote(['origin' => OriginType::Homemade->value]);

    expect($vote->origin)->toBe(OriginType::Homemade);
});
