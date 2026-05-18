<?php

use App\Actions\Votes\VotePostAction;

it('has vote post action class with handle method', function () {
    $reflection = new ReflectionClass(VotePostAction::class);

    expect($reflection->hasMethod('handle'))->toBeTrue();
});
