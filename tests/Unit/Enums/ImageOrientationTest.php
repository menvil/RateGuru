<?php

use App\Enums\ImageOrientation;

it('has exactly the three supported orientations', function () {
    expect(array_column(ImageOrientation::cases(), 'value'))
        ->toBe(['portrait', 'landscape', 'square']);
});
