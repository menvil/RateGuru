<?php

use App\Enums\MediaVisibility;

it('has exactly the two supported values', function () {
    expect(array_column(MediaVisibility::cases(), 'value'))->toBe(['private', 'public']);
});
