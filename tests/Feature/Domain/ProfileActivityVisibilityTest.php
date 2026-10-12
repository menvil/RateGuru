<?php

use App\Enums\ProfileActivityVisibility;

it('validates profile activity visibility values', function () {
    expect(ProfileActivityVisibility::isValid('private'))->toBeTrue();
    expect(ProfileActivityVisibility::isValid('public'))->toBeTrue();
    expect(ProfileActivityVisibility::isValid('followers_only'))->toBeFalse();
});
