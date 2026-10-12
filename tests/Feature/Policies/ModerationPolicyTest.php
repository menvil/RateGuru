<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('does not allow normal user to moderate content', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('moderate-content'))->toBeFalse();
});

it('does not allow trusted user to moderate content', function () {
    $trustedUser = User::factory()->trusted()->create();

    expect(Gate::forUser($trustedUser)->allows('moderate-content'))->toBeFalse();
});

it('does not allow normal user to ban user', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('ban-user'))->toBeFalse();
});

it('allows moderator to moderate content', function () {
    $moderator = User::factory()->moderator()->create();

    expect(Gate::forUser($moderator)->allows('moderate-content'))->toBeTrue();
});

it('allows admin to moderate content', function () {
    $admin = User::factory()->admin()->create();

    expect(Gate::forUser($admin)->allows('moderate-content'))->toBeTrue();
});

it('does not allow moderator to ban user', function () {
    $moderator = User::factory()->moderator()->create();

    expect(Gate::forUser($moderator)->allows('ban-user'))->toBeFalse();
});

it('allows admin to ban user', function () {
    $admin = User::factory()->admin()->create();

    expect(Gate::forUser($admin)->allows('ban-user'))->toBeTrue();
});
