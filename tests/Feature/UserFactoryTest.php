<?php

use App\Enums\UserRole;
use App\Models\User;

it('can create an admin user', function () {
    $user = User::factory()->admin()->create();

    expect($user->role)->toBe(UserRole::Admin);
});

it('can create a moderator user', function () {
    $user = User::factory()->moderator()->create();

    expect($user->role)->toBe(UserRole::Moderator);
});
