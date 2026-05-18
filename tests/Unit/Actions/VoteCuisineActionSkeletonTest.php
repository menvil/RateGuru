<?php

use App\Actions\Votes\VoteCuisineAction;

it('has vote cuisine action class with handle method', function () {
    $reflection = new ReflectionClass(VoteCuisineAction::class);

    expect($reflection->hasMethod('handle'))->toBeTrue();
    expect($reflection->getMethod('handle')->isPublic())->toBeTrue();
});
