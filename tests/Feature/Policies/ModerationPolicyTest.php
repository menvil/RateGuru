<?php

use App\Models\User;
use App\Policies\ModerationPolicy;
use Illuminate\Support\Facades\Gate;

it('has moderation gates registered', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('moderate-content'))->toBeFalse();
    expect(Gate::forUser($user)->allows('ban-user'))->toBeFalse();
});

it('has expected moderation policy methods', function () {
    $policy = app(ModerationPolicy::class);

    expect(method_exists($policy, 'moderateContent'))->toBeTrue();
    expect(method_exists($policy, 'banUser'))->toBeTrue();
});

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
