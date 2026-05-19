<?php

use App\Actions\Moderation\HidePostAction;

it('has hide post action with handle method', function () {
    $action = app(HidePostAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
