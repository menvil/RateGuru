<?php

use App\Actions\Moderation\RejectPostAction;

it('has reject post action with handle method', function () {
    $action = app(RejectPostAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
