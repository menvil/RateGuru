<?php

use App\Actions\Moderation\BanUserAction;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;

it('allows admin to ban user', function () {
    $admin = User::factory()->admin()->create();

    $target = User::factory()->create([
        'status' => UserStatus::Active,
    ]);

    app(BanUserAction::class)->handle(
        admin: $admin,
        target: $target,
        reason: 'Repeated abuse.'
    );

    expect($target->fresh()->status)->toBe(UserStatus::Banned);
});

it('does not allow moderator to ban user', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create();

    try {
        app(BanUserAction::class)->handle($moderator, $target);
        $this->fail('Expected CannotModerateUserException.');
    } catch (CannotModerateUserException $e) {
        expect($target->fresh()->status)->toBe(UserStatus::Active);
    }
});

it('does not allow normal user to ban user', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    app(BanUserAction::class)->handle($user, $target);
})->throws(CannotModerateUserException::class);

it('does not allow admin to ban self', function () {
    $admin = User::factory()->admin()->create();

    app(BanUserAction::class)->handle($admin, $admin);
})->throws(CannotModerateUserException::class);

it('does not allow admin to ban another admin', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->admin()->create();

    app(BanUserAction::class)->handle($admin, $target);
})->throws(CannotModerateUserException::class);
