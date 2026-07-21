<?php

use App\Data\Posts\CreatePostData;

it('can create create post data object', function () {
    $data = new CreatePostData(
        title: 'Sample entry',
        description: 'Simple dinner',
        sourceUrl: 'https://example.com/source',
        tagIds: [1, 2],
        image: null,
    );

    expect($data->title)->toBe('Sample entry');
    expect($data->description)->toBe('Simple dinner');
    expect($data->sourceUrl)->toBe('https://example.com/source');
    expect($data->tagIds)->toBe([1, 2]);
    expect($data->image)->toBeNull();
});

it('can create create post data object with defaults', function () {
    $data = new CreatePostData(title: 'Simple title');

    expect($data->description)->toBeNull();
    expect($data->sourceUrl)->toBeNull();
    expect($data->tagIds)->toBe([]);
    expect($data->image)->toBeNull();
});
