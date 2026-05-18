<?php

use App\Actions\Comments\DeleteCommentAction;

it('has delete comment action with handle method', function () {
    $action = app(DeleteCommentAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
