<?php

use App\Actions\Votes\VoteOriginAction;

it('has vote origin action class with handle method', function () {
    $reflection = new ReflectionClass(VoteOriginAction::class);

    expect($reflection->hasMethod('handle'))->toBeTrue();
});
