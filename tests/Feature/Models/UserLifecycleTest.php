<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Filament\Facades\Filament;

/*
 * User convenience methods must delegate to the central lifecycle contract
 * on UserStatus: for every status (and for a null status, which fails
 * closed) each User::canX() answer must equal the enum's answer. No
 * capability may re-implement lifecycle meaning on the model.
 */

dataset('lifecycle statuses', [
    'active' => [UserStatus::Active],
    'limited' => [UserStatus::Limited],
    'banned' => [UserStatus::Banned],
    'shadowbanned' => [UserStatus::Shadowbanned],
    'deleted' => [UserStatus::Deleted],
    'null status' => [null],
]);

it('delegates every user capability to the UserStatus contract', function (?UserStatus $status) {
    $user = User::factory()->make(['status' => $status]);

    expect($user->canCreateContent())->toBe($status?->canCreateContent() ?? false)
        ->and($user->canComment())->toBe($status?->canComment() ?? false)
        ->and($user->canVote())->toBe($status?->canVote() ?? false)
        ->and($user->canReport())->toBe($status?->canReport() ?? false)
        ->and($user->canFollow())->toBe($status?->canFollow() ?? false)
        ->and($user->canBeFollowed())->toBe($status?->canBeFollowed() ?? false)
        ->and($user->canManageContent())->toBe($status?->canManageContent() ?? false)
        ->and($user->canUpdateProfile())->toBe($status?->canUpdateProfile() ?? false)
        ->and($user->canAuthenticate())->toBe($status?->canAuthenticate() ?? false);
})->with('lifecycle statuses');

it('fails closed on every capability when status is null', function () {
    $user = User::factory()->make(['status' => null]);

    expect($user->canCreateContent())->toBeFalse()
        ->and($user->canComment())->toBeFalse()
        ->and($user->canVote())->toBeFalse()
        ->and($user->canReport())->toBeFalse()
        ->and($user->canFollow())->toBeFalse()
        ->and($user->canBeFollowed())->toBeFalse()
        ->and($user->canManageContent())->toBeFalse()
        ->and($user->canUpdateProfile())->toBeFalse()
        ->and($user->canAuthenticate())->toBeFalse();
});

/*
 * Admin-panel access is the intersection of two independent dimensions:
 * lifecycle eligibility (status must be Active) AND role (Admin/Moderator).
 * Non-active lifecycle states fail closed even for privileged roles.
 */
it('grants panel access only to active admins and moderators', function (
    UserRole $role,
    ?UserStatus $status,
    bool $allowed,
) {
    $user = User::factory()->make(['role' => $role, 'status' => $status]);
    $panel = Filament::getPanel('admin');

    expect($user->canAccessPanel($panel))->toBe($allowed);
})->with([
    'active admin' => [UserRole::Admin, UserStatus::Active, true],
    'active moderator' => [UserRole::Moderator, UserStatus::Active, true],
    'active regular user' => [UserRole::User, UserStatus::Active, false],
    'limited admin' => [UserRole::Admin, UserStatus::Limited, false],
    'limited moderator' => [UserRole::Moderator, UserStatus::Limited, false],
    'banned admin' => [UserRole::Admin, UserStatus::Banned, false],
    'banned moderator' => [UserRole::Moderator, UserStatus::Banned, false],
    'shadowbanned admin' => [UserRole::Admin, UserStatus::Shadowbanned, false],
    'shadowbanned moderator' => [UserRole::Moderator, UserStatus::Shadowbanned, false],
    'deleted admin' => [UserRole::Admin, UserStatus::Deleted, false],
    'deleted moderator' => [UserRole::Moderator, UserStatus::Deleted, false],
    'null-status admin' => [UserRole::Admin, null, false],
]);
