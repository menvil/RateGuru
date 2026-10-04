<?php

use App\Enums\MediaStatus;

it('has the expected string values', function () {
    expect(MediaStatus::Uploaded->value)->toBe('uploaded');
    expect(MediaStatus::Processing->value)->toBe('processing');
    expect(MediaStatus::Ready->value)->toBe('ready');
    expect(MediaStatus::Failed->value)->toBe('failed');
});

it('has exactly the four supported statuses', function () {
    expect(array_column(MediaStatus::cases(), 'value'))
        ->toBe(['uploaded', 'processing', 'ready', 'failed']);
});
