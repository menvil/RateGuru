<?php

use App\Actions\Moderation\CreateModerationLogAction;

it('has create moderation log action with handle method', function () {
    $action = app(CreateModerationLogAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
