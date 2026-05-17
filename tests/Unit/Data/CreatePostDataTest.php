<?php

use App\Data\Posts\CreatePostData;
use App\Enums\CuisineType;
use App\Enums\OriginType;

it('can create create post data object', function () {
    $data = new CreatePostData(
        title: 'Homemade pasta',
        description: 'Simple dinner',
        sourceUrl: 'https://example.com/source',
        originTruth: OriginType::Homemade,
        cuisineTruth: CuisineType::Italian,
        tagIds: [1, 2],
        image: null,
    );

    expect($data->title)->toBe('Homemade pasta');
    expect($data->description)->toBe('Simple dinner');
    expect($data->sourceUrl)->toBe('https://example.com/source');
    expect($data->originTruth)->toBe(OriginType::Homemade);
    expect($data->cuisineTruth)->toBe(CuisineType::Italian);
    expect($data->tagIds)->toBe([1, 2]);
});
