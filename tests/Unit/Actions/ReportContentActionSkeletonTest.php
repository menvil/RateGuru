<?php

use App\Actions\Reports\ReportContentAction;

it('has report content action with handle method', function () {
    $action = app(ReportContentAction::class);

    expect(is_callable([$action, 'handle']))->toBeTrue();
    $method = new ReflectionMethod($action, 'handle');
    expect($method->isPublic())->toBeTrue();
});
