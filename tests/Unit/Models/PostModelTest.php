<?php

use App\Models\Post;

it('has a Post model using posts table', function () {
    expect((new Post)->getTable())->toBe('posts');
});
