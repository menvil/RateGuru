<?php

use App\Models\User;
use App\Policies\UserPolicy;

it('allows admins to manage users', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    expect((new UserPolicy)->manage($admin, $target))->toBeTrue();
});

it('does not allow normal users to manage users', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    expect((new UserPolicy)->manage($user, $target))->toBeFalse();
});

it('allows admins to ban users', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    expect((new UserPolicy)->ban($admin, $target))->toBeTrue();
});

it('does not allow normal users to ban users', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    expect((new UserPolicy)->ban($user, $target))->toBeFalse();
});

it('allows admins and moderators to view admin area', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create();

    expect((new UserPolicy)->viewAdmin($admin))->toBeTrue();
    expect((new UserPolicy)->viewAdmin($moderator))->toBeTrue();
});

it('does not allow normal users to view admin area', function () {
    $user = User::factory()->create();

    expect((new UserPolicy)->viewAdmin($user))->toBeFalse();
});

it('does not allow admins to manage themselves', function () {
    $admin = User::factory()->admin()->create();

    expect((new UserPolicy)->manage($admin, $admin))->toBeFalse();
});

it('does not allow admins to manage other admins', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    expect((new UserPolicy)->manage($admin, $otherAdmin))->toBeFalse();
});

it('does not allow admins to ban themselves', function () {
    $admin = User::factory()->admin()->create();

    expect((new UserPolicy)->ban($admin, $admin))->toBeFalse();
});

it('does not allow admins to ban other admins', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    expect((new UserPolicy)->ban($admin, $otherAdmin))->toBeFalse();
});

dataset('admin sanction abilities', ['unban', 'shadowban']);

it('allows admins to sanction a non-admin target', function (string $ability) {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    expect((new UserPolicy)->{$ability}($admin, $target))->toBeTrue();
})->with('admin sanction abilities');

it('does not allow non-admins to sanction users', function (string $ability) {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create();

    expect((new UserPolicy)->{$ability}($moderator, $target))->toBeFalse();
})->with('admin sanction abilities');

it('does not allow admins to sanction themselves or other admins', function (string $ability) {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    expect((new UserPolicy)->{$ability}($admin, $admin))->toBeFalse();
    expect((new UserPolicy)->{$ability}($admin, $otherAdmin))->toBeFalse();
})->with('admin sanction abilities');

it('allows admins to mark a regular active-eligible user as trusted', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    expect((new UserPolicy)->markTrusted($admin, $target))->toBeTrue();
});

it('does not allow marking a moderator or admin as trusted', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create();
    $otherAdmin = User::factory()->admin()->create();

    expect((new UserPolicy)->markTrusted($admin, $moderator))->toBeFalse();
    expect((new UserPolicy)->markTrusted($admin, $otherAdmin))->toBeFalse();
});

it('does not allow non-admins to mark users as trusted', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create();

    expect((new UserPolicy)->markTrusted($moderator, $target))->toBeFalse();
});
