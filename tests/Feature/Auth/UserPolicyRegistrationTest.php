<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('resolves the registered user policy', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    expect(Gate::forUser($admin)->allows('manage', $target))->toBeTrue();
});

it('does not allow normal users via gate', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    expect(Gate::forUser($user)->allows('manage', $target))->toBeFalse();
});
