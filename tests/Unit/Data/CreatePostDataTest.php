<?php

use App\Data\Posts\CreatePostData;

it('can create create post data object with defaults', function () {
    $data = new CreatePostData(title: 'Simple title');

    expect($data->description)->toBeNull();
    expect($data->sourceUrl)->toBeNull();
    expect($data->tagIds)->toBe([]);
    expect($data->image)->toBeNull();
});
