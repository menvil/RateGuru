<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

it('can create an admin user', function () {
    $user = User::factory()->admin()->create();

    expect($user->role)->toBe(UserRole::Admin);
});

it('can create a moderator user', function () {
    $user = User::factory()->moderator()->create();

    expect($user->role)->toBe(UserRole::Moderator);
});

it('can create a banned user', function () {
    $user = User::factory()->banned()->create();

    expect($user->status)->toBe(UserStatus::Banned);
});

it('can create a trusted user', function () {
    $user = User::factory()->trusted()->create();

    expect($user->trust_level)->toBeGreaterThanOrEqual(10);
});
