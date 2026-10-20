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

/*
 * A Persona is never staff and never signs in for itself, whatever its
 * status — but its lifecycle is anyone's: every participation capability
 * is still the one its status gives.
 */
it('never grants a persona the panel, whatever its status', function (?UserStatus $status) {
    $persona = User::factory()->persona()->make(['status' => $status]);

    expect($persona->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
})->with('lifecycle statuses');

it('lets an account sign in only while it is living and not a persona', function (UserRole $role, ?UserStatus $status) {
    $user = User::factory()->make(['role' => $role, 'status' => $status]);

    expect($user->canSignIn())->toBe(($status?->canAuthenticate() ?? false) && $role !== UserRole::Persona)
        ->and($user->canAuthenticate())->toBe($status?->canAuthenticate() ?? false);
})->with(UserRole::cases())->with('lifecycle statuses');

it('leaves a persona every capability its status gives', function (?UserStatus $status) {
    $persona = User::factory()->persona()->make(['status' => $status]);

    expect($persona->canCreateContent())->toBe($status?->canCreateContent() ?? false)
        ->and($persona->canComment())->toBe($status?->canComment() ?? false)
        ->and($persona->canVote())->toBe($status?->canVote() ?? false)
        ->and($persona->canReport())->toBe($status?->canReport() ?? false)
        ->and($persona->canFollow())->toBe($status?->canFollow() ?? false)
        ->and($persona->canBeFollowed())->toBe($status?->canBeFollowed() ?? false)
        ->and($persona->canManageContent())->toBe($status?->canManageContent() ?? false)
        ->and($persona->canUpdateProfile())->toBe($status?->canUpdateProfile() ?? false)
        ->and($persona->canAuthenticate())->toBe($status?->canAuthenticate() ?? false);
})->with('lifecycle statuses');

it('answers the role questions from UserRole, failing closed without a role', function (?UserRole $role, bool $staff, bool $persona) {
    $user = User::factory()->make(['role' => $role]);

    expect($user->isStaff())->toBe($staff)
        ->and($user->isPersona())->toBe($persona);
})->with([
    'user' => [UserRole::User, false, false],
    'persona' => [UserRole::Persona, false, true],
    'moderator' => [UserRole::Moderator, true, false],
    'admin' => [UserRole::Admin, true, false],
    'no role' => [null, false, false],
]);

it('makes a test persona without a password, a confirmed address or a sign-in identity', function () {
    $persona = User::factory()->persona()->create();

    expect($persona->role)->toBe(UserRole::Persona)
        ->and($persona->status)->toBe(UserStatus::Active)
        ->and($persona->password)->toBeNull()
        ->and($persona->remember_token)->toBeNull()
        ->and($persona->hasVerifiedEmail())->toBeFalse()
        ->and($persona->email)->toEndWith('.invalid')
        ->and($persona->socialAccounts()->count())->toBe(0)
        ->and(User::factory()->persona()->create()->email)->not->toBe($persona->email);
});
