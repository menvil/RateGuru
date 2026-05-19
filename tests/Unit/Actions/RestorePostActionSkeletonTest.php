<?php

use App\Actions\Moderation\RestorePostAction;

it('has restore post action with handle method', function () {
    $action = app(RestorePostAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
