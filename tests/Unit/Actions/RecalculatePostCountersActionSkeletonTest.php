<?php

use App\Actions\Counters\RecalculatePostCountersAction;

it('has recalculate post counters action with handle method', function () {
    $action = app(RecalculatePostCountersAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
