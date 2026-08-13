<?php

use App\Actions\Moderation\RestoreUserAccessAction;
use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;

it('restores access for every living sanction', function (string $state) {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->{$state}()->create();

    app(RestoreUserAccessAction::class)->handle(
        admin: $admin,
        target: $target,
        reason: 'Appeal accepted.'
    );

    expect($target->fresh()->status)->toBe(UserStatus::Active);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'action' => ModerationActionType::RestoreUserAccess->value,
        'target_type' => User::class,
        'target_id' => $target->id,
        'reason' => 'Appeal accepted.',
    ]);
})->with(['limited', 'banned', 'shadowbanned']);

it('refuses to restore another admin', function () {
    $admin = User::factory()->admin()->create();
    // Defence in depth: sanctions refuse admin targets, but if one ever
    // ends up banned via schema drift or manual DB edits, restore must
    // still refuse to act on them.
    $bannedAdmin = User::factory()->admin()->create(['status' => UserStatus::Banned]);

    expect(fn () => app(RestoreUserAccessAction::class)->handle($admin, $bannedAdmin))
        ->toThrow(CannotModerateUserException::class);

    expect($bannedAdmin->fresh()->status)->toBe(UserStatus::Banned);
});

it('refuses to restore self', function () {
    $admin = User::factory()->admin()->create();

    expect(fn () => app(RestoreUserAccessAction::class)->handle($admin, $admin))
        ->toThrow(CannotModerateUserException::class);
});

it('does not allow a moderator to restore access', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->banned()->create();

    expect(fn () => app(RestoreUserAccessAction::class)->handle($moderator, $target))
        ->toThrow(CannotModerateUserException::class);

    expect($target->fresh()->status)->toBe(UserStatus::Banned);
});

it('does not allow a sanctioned admin to restore access', function (string $adminState) {
    $sanctionedAdmin = User::factory()->admin()->{$adminState}()->create();
    $target = User::factory()->banned()->create();

    expect(fn () => app(RestoreUserAccessAction::class)->handle($sanctionedAdmin, $target))
        ->toThrow(CannotModerateUserException::class);

    expect($target->fresh()->status)->toBe(UserStatus::Banned);
})->with(['limited', 'banned', 'shadowbanned']);

it('is invalid for an already Active target and writes no log', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['status' => UserStatus::Active]);

    expect(fn () => app(RestoreUserAccessAction::class)->handle($admin, $target))
        ->toThrow(CannotModerateUserException::class);

    expect(App\Models\ModerationLog::query()->count())->toBe(0);
});

it('never restores a Deleted tombstone', function () {
    $admin = User::factory()->admin()->create();
    $tombstone = User::factory()->create(['status' => UserStatus::Deleted]);

    expect(fn () => app(RestoreUserAccessAction::class)->handle($admin, $tombstone))
        ->toThrow(CannotModerateUserException::class);

    expect($tombstone->fresh()->status)->toBe(UserStatus::Deleted);
});
