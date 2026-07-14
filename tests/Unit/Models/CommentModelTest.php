<?php

use App\Models\Comment;

it('has a Comment model using comments table', function () {
    expect((new Comment)->getTable())->toBe('comments');
});
