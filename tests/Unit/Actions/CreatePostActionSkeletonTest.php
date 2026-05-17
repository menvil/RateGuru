<?php

use App\Actions\Posts\CreatePostAction;

it('has create post action class with handle method', function () {
    $reflection = new ReflectionClass(CreatePostAction::class);

    expect($reflection->hasMethod('handle'))->toBeTrue();
});
