<?php

use App\Enums\UserStatus;

it('contains expected user statuses', function () {
    expect(UserStatus::Active->value)->toBe('active');
    expect(UserStatus::Limited->value)->toBe('limited');
    expect(UserStatus::Banned->value)->toBe('banned');
    expect(UserStatus::Shadowbanned->value)->toBe('shadowbanned');
});

it('knows whether a status can create content', function () {
    expect(UserStatus::Active->canCreateContent())->toBeTrue();
    expect(UserStatus::Banned->canCreateContent())->toBeFalse();
    expect(UserStatus::Limited->canCreateContent())->toBeFalse();
    expect(UserStatus::Shadowbanned->canCreateContent())->toBeFalse();
});
