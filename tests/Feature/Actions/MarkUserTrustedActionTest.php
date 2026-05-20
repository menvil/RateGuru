<?php

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;

it('allows admin to mark an active normal user as trusted', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create([
        'status' => UserStatus::Active,
        'trust_level' => 0,
    ]);

    app(MarkUserTrustedAction::class)->handle(
        admin: $admin,
        target: $target,
        reason: 'Reliable contributor.'
    );

    $fresh = $target->fresh();
    expect($fresh->trust_level)->toBe(MarkUserTrustedAction::TRUSTED_LEVEL);
    expect($fresh->status)->toBe(UserStatus::Active);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'action' => ModerationActionType::MarkUserTrusted->value,
        'target_type' => User::class,
        'target_id' => $target->id,
        'reason' => 'Reliable contributor.',
    ]);
});

it('does not allow moderator to mark a user trusted', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create();

    expect(fn () => app(MarkUserTrustedAction::class)->handle($moderator, $target))
        ->toThrow(CannotModerateUserException::class);
});

it('refuses to mark an admin or moderator trusted', function () {
    $admin = User::factory()->admin()->create();
    $otherMod = User::factory()->moderator()->create();

    expect(fn () => app(MarkUserTrustedAction::class)->handle($admin, $otherMod))
        ->toThrow(CannotModerateUserException::class);
});

it('refuses to mark the acting admin themselves trusted', function () {
    $admin = User::factory()->admin()->create();

    expect(fn () => app(MarkUserTrustedAction::class)->handle($admin, $admin))
        ->toThrow(CannotModerateUserException::class);
});

it('refuses to mark a banned user trusted', function () {
    $admin = User::factory()->admin()->create();
    $banned = User::factory()->banned()->create();

    expect(fn () => app(MarkUserTrustedAction::class)->handle($admin, $banned))
        ->toThrow(CannotModerateUserException::class);
});

it('refuses to mark an already trusted user trusted again', function () {
    $admin = User::factory()->admin()->create();
    $trusted = User::factory()->trusted()->create();

    expect(fn () => app(MarkUserTrustedAction::class)->handle($admin, $trusted))
        ->toThrow(CannotModerateUserException::class);
});
