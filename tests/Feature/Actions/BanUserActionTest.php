<?php

use App\Actions\Moderation\BanUserAction;
use App\Enums\ModerationActionType;
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

it('writes moderation log when banning user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    app(BanUserAction::class)->handle($admin, $target, 'Abuse.');

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'action' => ModerationActionType::BanUser->value,
        'target_type' => User::class,
        'target_id' => $target->id,
    ]);
});

it('does not write moderation log when moderator fails to ban', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create();

    try {
        app(BanUserAction::class)->handle($moderator, $target);
        $this->fail('Expected CannotModerateUserException.');
    } catch (CannotModerateUserException $e) {
    }

    $this->assertDatabaseCount('moderation_logs', 0);
});

it('does not ban already banned user and writes no moderation log', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['status' => UserStatus::Banned]);

    try {
        app(BanUserAction::class)->handle($admin, $target);
        $this->fail('Expected CannotModerateUserException.');
    } catch (CannotModerateUserException $e) {
        expect($target->fresh()->status)->toBe(UserStatus::Banned);
        $this->assertDatabaseCount('moderation_logs', 0);
    }
});
