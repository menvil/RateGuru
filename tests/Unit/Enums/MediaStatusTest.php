<?php

use App\Enums\MediaStatus;

it('has exactly the four supported statuses', function () {
    expect(array_column(MediaStatus::cases(), 'value'))
        ->toBe(['uploaded', 'processing', 'ready', 'failed']);
});
