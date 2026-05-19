<?php

use App\Actions\Moderation\ShadowbanUserAction;
use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;

it('allows admin to shadowban user', function () {
    $admin = User::factory()->admin()->create();

    $target = User::factory()->create([
        'status' => UserStatus::Active,
    ]);

    app(ShadowbanUserAction::class)->handle(
        admin: $admin,
        target: $target,
        reason: 'Suspicious behavior.'
    );

    expect($target->fresh()->status)->toBe(UserStatus::Shadowbanned);
});

it('does not allow moderator to shadowban user', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create();

    try {
        app(ShadowbanUserAction::class)->handle($moderator, $target);
        $this->fail('Expected CannotModerateUserException.');
    } catch (CannotModerateUserException $e) {
        expect($target->fresh()->status)->toBe(UserStatus::Active);
    }
});

it('does not allow normal user to shadowban user', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    app(ShadowbanUserAction::class)->handle($user, $target);
})->throws(CannotModerateUserException::class);

it('does not allow admin to shadowban self', function () {
    $admin = User::factory()->admin()->create();

    app(ShadowbanUserAction::class)->handle($admin, $admin);
})->throws(CannotModerateUserException::class);

it('does not allow admin to shadowban another admin', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->admin()->create();

    app(ShadowbanUserAction::class)->handle($admin, $target);
})->throws(CannotModerateUserException::class);

it('writes moderation log when shadowbanning user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    app(ShadowbanUserAction::class)->handle($admin, $target, 'Suspicious.');

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'action' => ModerationActionType::ShadowbanUser->value,
        'target_type' => User::class,
        'target_id' => $target->id,
    ]);
});

it('does not write moderation log when moderator fails to shadowban', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create();

    try {
        app(ShadowbanUserAction::class)->handle($moderator, $target);
        $this->fail('Expected CannotModerateUserException.');
    } catch (CannotModerateUserException $e) {
    }

    $this->assertDatabaseCount('moderation_logs', 0);
});
