<?php

use App\Enums\UserRole;

it('contains expected user roles', function () {
    expect(UserRole::User->value)->toBe('user');
    expect(UserRole::Moderator->value)->toBe('moderator');
    expect(UserRole::Admin->value)->toBe('admin');
});
