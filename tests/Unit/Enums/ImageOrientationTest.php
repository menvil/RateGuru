<?php

use App\Enums\ImageOrientation;

it('has the expected string values', function () {
    expect(ImageOrientation::Portrait->value)->toBe('portrait');
    expect(ImageOrientation::Landscape->value)->toBe('landscape');
    expect(ImageOrientation::Square->value)->toBe('square');
});

it('has exactly the three supported orientations', function () {
    expect(array_column(ImageOrientation::cases(), 'value'))
        ->toBe(['portrait', 'landscape', 'square']);
});
