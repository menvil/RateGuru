<?php

use App\Actions\Moderation\BanUserAction;
use App\Actions\Moderation\LimitUserAction;
use App\Actions\Moderation\RestoreUserAccessAction;
use App\Actions\Moderation\ShadowbanUserAction;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\ModerationLog;
use App\Models\User;

/*
 * The full PR-F transition matrix (docs/architecture/user-lifecycle.md).
 * Downgrading a Ban requires restore-to-Active first; Deleted never
 * re-enters the lifecycle; every valid transition logs exactly once with
 * the authoritative fresh from_status.
 */

function sanctionAction(UserStatus $to): string
{
    return match ($to) {
        UserStatus::Limited => LimitUserAction::class,
        UserStatus::Banned => BanUserAction::class,
        UserStatus::Shadowbanned => ShadowbanUserAction::class,
        UserStatus::Active => RestoreUserAccessAction::class,
    };
}

it('permits every allowed transition and logs exactly once with the fresh from_status', function (UserStatus $from, UserStatus $to) {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['status' => $from]);

    app(sanctionAction($to))->handle($admin, $target);

    expect($target->fresh()->status)->toBe($to)
        ->and(ModerationLog::query()->count())->toBe(1);

    $log = ModerationLog::query()->firstOrFail();
    expect($log->metadata['from_status'])->toBe($from->value)
        ->and($log->metadata['to_status'])->toBe($to->value)
        ->and($log->moderator_id)->toBe($admin->id);
})->with([
    'active -> limited' => [UserStatus::Active, UserStatus::Limited],
    'active -> banned' => [UserStatus::Active, UserStatus::Banned],
    'active -> shadowbanned' => [UserStatus::Active, UserStatus::Shadowbanned],
    'limited -> active' => [UserStatus::Limited, UserStatus::Active],
    'limited -> banned' => [UserStatus::Limited, UserStatus::Banned],
    'limited -> shadowbanned' => [UserStatus::Limited, UserStatus::Shadowbanned],
    'shadowbanned -> active' => [UserStatus::Shadowbanned, UserStatus::Active],
    'shadowbanned -> banned' => [UserStatus::Shadowbanned, UserStatus::Banned],
    'banned -> active' => [UserStatus::Banned, UserStatus::Active],
]);

it('rejects every forbidden transition and writes no log', function (UserStatus $from, UserStatus $to) {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['status' => $from]);

    expect(fn () => app(sanctionAction($to))->handle($admin, $target))
        ->toThrow(CannotModerateUserException::class);

    expect($target->fresh()->status)->toBe($from)
        ->and(ModerationLog::query()->count())->toBe(0);
})->with([
    // Same-state duplicates are invalid, never silent re-logs.
    'limit limited' => [UserStatus::Limited, UserStatus::Limited],
    'ban banned' => [UserStatus::Banned, UserStatus::Banned],
    'shadowban shadowbanned' => [UserStatus::Shadowbanned, UserStatus::Shadowbanned],
    'restore active' => [UserStatus::Active, UserStatus::Active],
    // A ban can only be lifted to Active, never downgraded sideways.
    'banned -> limited' => [UserStatus::Banned, UserStatus::Limited],
    'banned -> shadowbanned' => [UserStatus::Banned, UserStatus::Shadowbanned],
    // Escalation chains that skip the matrix.
    'shadowbanned -> limited' => [UserStatus::Shadowbanned, UserStatus::Limited],
    // Deleted is terminal in every direction.
    'deleted -> limited' => [UserStatus::Deleted, UserStatus::Limited],
    'deleted -> banned' => [UserStatus::Deleted, UserStatus::Banned],
    'deleted -> shadowbanned' => [UserStatus::Deleted, UserStatus::Shadowbanned],
    'deleted -> active' => [UserStatus::Deleted, UserStatus::Active],
]);

it('rejects a stale-admin sanction after the admin was sanctioned', function () {
    // The caller object still says Active Admin; the authoritative locked
    // re-read inside the transaction must see the sanctioned row.
    $superAdmin = User::factory()->admin()->create();
    $staleAdmin = User::factory()->admin()->create();
    $target = User::factory()->create();

    // Sanction the admin behind the stale instance's back. Admins cannot
    // sanction admins, so demote first through the DB (schema-drift shape)
    // to keep the scenario reachable.
    User::query()->whereKey($staleAdmin->id)->update(['status' => UserStatus::Banned]);

    expect($staleAdmin->status)->toBe(UserStatus::Active);

    expect(fn () => app(LimitUserAction::class)->handle($staleAdmin, $target))
        ->toThrow(CannotModerateUserException::class);

    expect($target->fresh()->status)->toBe(UserStatus::Active)
        ->and(ModerationLog::query()->count())->toBe(0);
});

it('rejects sanctions from a non-active admin outright', function (string $adminState) {
    $sanctionedAdmin = User::factory()->admin()->{$adminState}()->create();
    $target = User::factory()->create();

    foreach ([LimitUserAction::class, BanUserAction::class, ShadowbanUserAction::class] as $action) {
        expect(fn () => app($action)->handle($sanctionedAdmin, $target))
            ->toThrow(CannotModerateUserException::class);
    }

    expect($target->fresh()->status)->toBe(UserStatus::Active);
})->with(['limited', 'banned', 'shadowbanned']);

it('logs the authoritative from_status when the caller target instance is stale', function () {
    $admin = User::factory()->admin()->create();
    $staleTarget = User::factory()->create(['status' => UserStatus::Active]);

    // The row moves to Limited behind the instance's back; banning must
    // log limited -> banned, not active -> banned.
    User::query()->whereKey($staleTarget->id)->update(['status' => UserStatus::Limited]);

    app(BanUserAction::class)->handle($admin, $staleTarget);

    $log = ModerationLog::query()->firstOrFail();
    expect($log->metadata['from_status'])->toBe(UserStatus::Limited->value)
        ->and($log->metadata['to_status'])->toBe(UserStatus::Banned->value);
});

it('keeps role and trust untouched through sanction and restore', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create(['trust_level' => 7]);

    app(BanUserAction::class)->handle($admin, $moderator);

    $fresh = $moderator->fresh();
    expect($fresh->role)->toBe(App\Enums\UserRole::Moderator)
        ->and((int) $fresh->trust_level)->toBe(7)
        ->and($fresh->status)->toBe(UserStatus::Banned);

    app(RestoreUserAccessAction::class)->handle($admin, $fresh);

    $restored = $moderator->fresh();
    expect($restored->role)->toBe(App\Enums\UserRole::Moderator)
        ->and((int) $restored->trust_level)->toBe(7)
        ->and($restored->status)->toBe(UserStatus::Active);
});
