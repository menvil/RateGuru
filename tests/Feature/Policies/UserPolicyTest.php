<?php

use App\Models\User;
use App\Policies\UserPolicy;

it('allows admins to manage users', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    expect((new UserPolicy())->manage($admin, $target))->toBeTrue();
});

it('does not allow normal users to manage users', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    expect((new UserPolicy())->manage($user, $target))->toBeFalse();
});

it('allows admins to ban users', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    expect((new UserPolicy())->ban($admin, $target))->toBeTrue();
});

it('does not allow normal users to ban users', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    expect((new UserPolicy())->ban($user, $target))->toBeFalse();
});

it('allows admins and moderators to view admin area', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create();

    expect((new UserPolicy())->viewAdmin($admin))->toBeTrue();
    expect((new UserPolicy())->viewAdmin($moderator))->toBeTrue();
});

it('does not allow normal users to view admin area', function () {
    $user = User::factory()->create();

    expect((new UserPolicy())->viewAdmin($user))->toBeFalse();
});
