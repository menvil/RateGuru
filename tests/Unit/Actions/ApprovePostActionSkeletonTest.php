<?php

use App\Actions\Moderation\ApprovePostAction;

it('has approve post action with handle method', function () {
    $action = app(ApprovePostAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
