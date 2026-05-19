<?php

use App\Actions\Reports\ResolveReportAction;

it('has resolve report action with handle method', function () {
    $action = app(ResolveReportAction::class);

    expect(is_callable([$action, 'handle']))->toBeTrue();
    $method = new ReflectionMethod($action, 'handle');
    expect($method->isPublic())->toBeTrue();
});
