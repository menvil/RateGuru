<?php

use App\Actions\Comments\AddCommentAction;

it('has add comment action with handle method', function () {
    $action = app(AddCommentAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
