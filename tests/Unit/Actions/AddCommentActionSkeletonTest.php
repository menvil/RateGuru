<?php

use App\Actions\Comments\AddCommentAction;

it('has add comment action with handle method', function () {
    $action = app(AddCommentAction::class);

    expect(is_callable([$action, 'handle']))->toBeTrue();
    $method = new ReflectionMethod($action, 'handle');
    expect($method->isPublic())->toBeTrue();
});
