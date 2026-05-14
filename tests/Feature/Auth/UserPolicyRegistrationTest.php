<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('resolves the registered user policy', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    expect(Gate::forUser($admin)->allows('manage', $target))->toBeTrue();
    expect(Gate::forUser($admin)->allows('ban', $target))->toBeTrue();
    expect(Gate::forUser($admin)->allows('viewAdmin', User::class))->toBeTrue();
});

it('does not allow normal users via gate', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    expect(Gate::forUser($user)->allows('manage', $target))->toBeFalse();
    expect(Gate::forUser($user)->allows('ban', $target))->toBeFalse();
    expect(Gate::forUser($user)->allows('viewAdmin'))->toBeFalse();
});
