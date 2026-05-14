<?php

use App\Enums\CuisineType;
use App\Models\CuisineVote;

it('casts cuisine vote cuisine to CuisineType enum', function () {
    $vote = new CuisineVote(['cuisine' => CuisineType::Italian]);

    expect($vote->cuisine)->toBe(CuisineType::Italian);
});
