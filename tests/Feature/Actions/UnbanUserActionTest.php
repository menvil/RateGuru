<?php

use App\Actions\Moderation\UnbanUserAction;
use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;

it('allows admin to unban a banned user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->banned()->create();

    app(UnbanUserAction::class)->handle(
        admin: $admin,
        target: $target,
        reason: 'Appeal accepted.'
    );

    expect($target->fresh()->status)->toBe(UserStatus::Active);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'action' => ModerationActionType::UnbanUser->value,
        'target_type' => User::class,
        'target_id' => $target->id,
    ]);
});

it('allows admin to unban a shadowbanned user', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['status' => UserStatus::Shadowbanned]);

    app(UnbanUserAction::class)->handle(admin: $admin, target: $target);

    expect($target->fresh()->status)->toBe(UserStatus::Active);
});

it('does not allow moderator to unban a user', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->banned()->create();

    expect(fn () => app(UnbanUserAction::class)->handle($moderator, $target))
        ->toThrow(CannotModerateUserException::class);

    expect($target->fresh()->status)->toBe(UserStatus::Banned);
});

it('refuses to unban a user whose status is not banned or shadowbanned', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['status' => UserStatus::Active]);

    expect(fn () => app(UnbanUserAction::class)->handle($admin, $target))
        ->toThrow(CannotModerateUserException::class);
});
