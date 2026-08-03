<?php

use App\Enums\MediaKind;

it('has the expected string values', function () {
    expect(MediaKind::PostImage->value)->toBe('post_image');
    expect(MediaKind::Avatar->value)->toBe('avatar');
});

it('has exactly the two supported kinds', function () {
    expect(array_column(MediaKind::cases(), 'value'))->toBe(['post_image', 'avatar']);
});
