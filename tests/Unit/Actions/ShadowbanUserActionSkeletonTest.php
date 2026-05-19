<?php

use App\Actions\Moderation\ShadowbanUserAction;

it('has shadowban user action with handle method', function () {
    $action = app(ShadowbanUserAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
