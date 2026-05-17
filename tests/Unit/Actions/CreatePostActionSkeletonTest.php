<?php

use App\Actions\Posts\CreatePostAction;

it('has create post action class with handle method', function () {
    $action = app(CreatePostAction::class);

    expect(method_exists($action, 'handle'))->toBeTrue();
});
