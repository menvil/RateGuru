<?php

use App\Enums\CuisineType;
use App\Models\CuisineVote;

it('casts cuisine vote cuisine to CuisineType enum', function () {
    $vote = new CuisineVote(['cuisine' => CuisineType::Italian->value]);

    expect($vote->cuisine)->toBe(CuisineType::Italian);
});
