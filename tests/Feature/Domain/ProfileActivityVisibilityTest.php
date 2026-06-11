<?php

use App\Enums\ProfileActivityVisibility;

it('validates profile activity visibility values', function () {
    expect(ProfileActivityVisibility::isValid('private'))->toBeTrue();
    expect(ProfileActivityVisibility::isValid('public'))->toBeTrue();
    expect(ProfileActivityVisibility::isValid('followers_only'))->toBeFalse();
});

it('has private and public cases', function () {
    expect(ProfileActivityVisibility::Private->value)->toBe('private');
    expect(ProfileActivityVisibility::Public->value)->toBe('public');
});

it('lists supported values', function () {
    $values = ProfileActivityVisibility::values();

    expect($values)->toContain('private');
    expect($values)->toContain('public');
    expect($values)->not->toContain('followers_only');
});
