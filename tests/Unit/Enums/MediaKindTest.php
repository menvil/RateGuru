<?php

use App\Enums\MediaKind;

it('has exactly the two supported kinds', function () {
    expect(array_column(MediaKind::cases(), 'value'))->toBe(['post_image', 'avatar']);
});
