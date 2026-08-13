<?php

use App\Actions\Moderation\BanUserAction;
use App\Actions\Moderation\ShadowbanUserAction;
use App\Actions\Moderation\RestoreUserAccessAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\Comment;
use App\Models\Follow;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\User;

/*
 * Moderation lifecycle transitions: from_status/to_status audit accuracy
 * (including under concurrent status changes) and the non-destructive
 * invariant — ban/shadowban/unban modify participation state only and never
 * touch identity, posts, comments, follows, avatar or media. This contract
 * is what later account-deletion work (PR-C/PR-F) builds on.
 */

function latestModerationLogMetadata(): array
{
    /** @var ModerationLog $log */
    $log = ModerationLog::query()->latest('id')->firstOrFail();

    return (array) $log->metadata;
}

it('bans a limited user and logs the limited from_status', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->limited()->create();

    app(BanUserAction::class)->handle($admin, $target);

    expect($target->fresh()->status)->toBe(UserStatus::Banned);
    expect(latestModerationLogMetadata())->toBe([
        'from_status' => 'limited',
        'to_status' => 'banned',
    ]);
});

it('bans a shadowbanned user and logs the shadowbanned from_status', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->shadowbanned()->create();

    app(BanUserAction::class)->handle($admin, $target);

    expect($target->fresh()->status)->toBe(UserStatus::Banned);
    expect(latestModerationLogMetadata())->toBe([
        'from_status' => 'shadowbanned',
        'to_status' => 'banned',
    ]);
});

it('shadowbans a limited user and logs the limited from_status', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->limited()->create();

    app(ShadowbanUserAction::class)->handle($admin, $target);

    expect($target->fresh()->status)->toBe(UserStatus::Shadowbanned);
    expect(latestModerationLogMetadata())->toBe([
        'from_status' => 'limited',
        'to_status' => 'shadowbanned',
    ]);
});

it('unbans a shadowbanned user and logs both statuses', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->shadowbanned()->create();

    app(RestoreUserAccessAction::class)->handle($admin, $target);

    expect($target->fresh()->status)->toBe(UserStatus::Active);
    expect(latestModerationLogMetadata())->toBe([
        'from_status' => 'shadowbanned',
        'to_status' => 'active',
    ]);
});

it('logs the re-read current status when the passed model instance is stale', function () {
    // Concurrency regression: the action must re-read the target under
    // lockForUpdate() so the logged from_status matches what was actually
    // mutated — not whatever a stale in-memory instance still claims.
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(); // instance believes status is Active

    User::query()->whereKey($target->id)->update(['status' => UserStatus::Limited->value]);

    app(BanUserAction::class)->handle($admin, $target);

    expect(latestModerationLogMetadata())->toBe([
        'from_status' => 'limited',
        'to_status' => 'banned',
    ]);
    // The passed instance is synchronized with what was persisted.
    expect($target->status)->toBe(UserStatus::Banned);
});

/*
 * Non-destructive invariant. One fixture shape per scenario: a user with
 * posts, comments, an avatar asset and follows in both directions.
 */

function makeModeratableUserWithContent(): User
{
    $user = User::factory()->withAvatar()->create();

    Post::factory()->published()->for($user)->count(2)->create();
    Comment::factory()->for($user)->count(2)->create();

    $other = User::factory()->create();
    Follow::query()->create(['follower_id' => $user->id, 'author_id' => $other->id]);
    Follow::query()->create(['follower_id' => $other->id, 'author_id' => $user->id]);

    return $user->fresh();
}

function expectLifecycleContentPreserved(User $user, int $avatarAssetId): void
{
    $fresh = $user->fresh();

    expect($fresh->posts()->count())->toBe(2)
        ->and($fresh->comments()->count())->toBe(2)
        ->and($fresh->avatar_asset_id)->toBe($avatarAssetId)
        ->and($fresh->avatarAsset)->not->toBeNull()
        ->and($fresh->followingRelations()->count())->toBe(1)
        ->and($fresh->followerRelations()->count())->toBe(1)
        ->and($fresh->name)->toBe($user->name)
        ->and($fresh->email)->toBe($user->email);
}

it('preserves all content and identity when banning a user', function () {
    $admin = User::factory()->admin()->create();
    $target = makeModeratableUserWithContent();
    $avatarAssetId = $target->avatar_asset_id;

    app(BanUserAction::class)->handle($admin, $target);

    expect($target->fresh()->status)->toBe(UserStatus::Banned);
    expectLifecycleContentPreserved($target, $avatarAssetId);
});

it('preserves all content and identity when shadowbanning a user', function () {
    $admin = User::factory()->admin()->create();
    $target = makeModeratableUserWithContent();
    $avatarAssetId = $target->avatar_asset_id;

    app(ShadowbanUserAction::class)->handle($admin, $target);

    expect($target->fresh()->status)->toBe(UserStatus::Shadowbanned);
    expectLifecycleContentPreserved($target, $avatarAssetId);
});

it('preserves all content and identity across a ban and unban cycle', function () {
    $admin = User::factory()->admin()->create();
    $target = makeModeratableUserWithContent();
    $avatarAssetId = $target->avatar_asset_id;

    app(BanUserAction::class)->handle($admin, $target);
    app(RestoreUserAccessAction::class)->handle($admin, $target);

    expect($target->fresh()->status)->toBe(UserStatus::Active);
    expectLifecycleContentPreserved($target, $avatarAssetId);
});

it('rejects banning a tombstone even via a stale instance that predates deletion', function () {
    // The authorization gate sees the stale Active instance and passes;
    // the in-transaction lockForUpdate() re-read is what must catch the
    // concurrent deletion. Deleted is terminal for moderation.
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $stale = User::query()->findOrFail($target->id);

    app(AnonymizeUserAccountAction::class)->execute($target);

    expect(fn () => app(BanUserAction::class)->handle($admin, $stale))
        ->toThrow(CannotModerateUserException::class);

    expect($target->fresh()->status)->toBe(UserStatus::Deleted);
    expect(ModerationLog::query()->count())->toBe(0);
});

it('rejects shadowbanning a tombstone even via a stale instance that predates deletion', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $stale = User::query()->findOrFail($target->id);

    app(AnonymizeUserAccountAction::class)->execute($target);

    expect(fn () => app(ShadowbanUserAction::class)->handle($admin, $stale))
        ->toThrow(CannotModerateUserException::class);

    expect($target->fresh()->status)->toBe(UserStatus::Deleted);
    expect(ModerationLog::query()->count())->toBe(0);
});

it('records ban and unban as distinct moderation log entries', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    app(BanUserAction::class)->handle($admin, $target);
    app(RestoreUserAccessAction::class)->handle($admin, $target);

    $actions = ModerationLog::query()->orderBy('id')->pluck('action')->all();

    expect($actions)->toBe([ModerationActionType::BanUser, ModerationActionType::RestoreUserAccess]);
});
