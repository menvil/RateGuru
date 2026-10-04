<?php

use App\Enums\MediaVisibility;

it('has the expected string values', function () {
    expect(MediaVisibility::Private->value)->toBe('private');
    expect(MediaVisibility::Public->value)->toBe('public');
});

it('has exactly the two supported values', function () {
    expect(array_column(MediaVisibility::cases(), 'value'))->toBe(['private', 'public']);
});
