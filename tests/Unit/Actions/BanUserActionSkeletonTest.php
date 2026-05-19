<?php

use App\Actions\Moderation\BanUserAction;

it('has ban user action with handle method', function () {
    $action = app(BanUserAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
