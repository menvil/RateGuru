<?php

use App\Enums\OriginType;
use App\Models\OriginVote;

it('casts origin vote origin to OriginType enum', function () {
    $vote = new OriginVote(['origin' => OriginType::Homemade]);

    expect($vote->origin)->toBe(OriginType::Homemade);
});
