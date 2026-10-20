<?php

use App\Actions\Users\UpdateUserAccountAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Users\CannotUpdateUserAccountException;
use App\Models\ModerationLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * An administrator editing someone's account: identity, role, trust and a
 * new password — never the lifecycle status, and never an account the
 * administrator may not manage.
 */
function accountChanges(User $user, array $changes = []): array
{
    return ['name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'role' => $user->role, 'trusted' => null, ...$changes];
}

it('saves the identity, the role, the trust and a new password, and leaves the status alone', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['status' => UserStatus::Limited, 'trust_level' => 10]);
    $before = $user->password;

    app(UpdateUserAccountAction::class)->handle($admin, $user, accountChanges($user, [
        'name' => 'New Name', 'username' => 'new_name', 'role' => UserRole::Moderator, 'trusted' => false, 'password' => 'a-new-long-password',
    ]));

    $fresh = $user->fresh();

    expect($fresh)->name->toBe('New Name')->username->toBe('new_name')->role->toBe(UserRole::Moderator)->trust_level->toBe(0)->status->toBe(UserStatus::Limited)
        ->and(Hash::check('a-new-long-password', $fresh->password))->toBeTrue()
        ->and($fresh->password)->not->toBe($before)
        ->and(ModerationLog::query()->count())->toBe(0);
});

it('keeps the password without a new one, and asks for a new email address to be confirmed', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['email' => 'old@example.com']);
    $password = $user->password;

    app(UpdateUserAccountAction::class)->handle($admin, $user, accountChanges($user, ['email' => 'new@example.com', 'password' => null]));

    expect($user->fresh())->email->toBe('new@example.com')->email_verified_at->toBeNull()->password->toBe($password);
});

it('refuses anyone who may not manage the account', function (string $case) {
    $admin = User::factory()->admin()->create();
    [$actor, $target] = match ($case) {
        'a moderator' => [User::factory()->moderator()->create(), User::factory()->create()],
        'another administrator' => [$admin, User::factory()->admin()->create()],
        'their own account' => [$admin, $admin],
        default => [$admin, User::factory()->tombstoned()->create()],
    };

    app(UpdateUserAccountAction::class)->handle($actor, $target, accountChanges($target, ['name' => 'Forged']));
})->throws(CannotUpdateUserAccountException::class)->with(['a moderator', 'another administrator', 'their own account', 'a deleted account']);

/*
 * A Persona is made and edited only in Content Studio. This edit refuses it
 * in both directions and touches nothing of one — decided on the locked row,
 * so neither a forged request nor a stale page gets around it.
 */
it('never makes anyone a persona', function (UserRole $role) {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['role' => $role]);

    expect(fn () => app(UpdateUserAccountAction::class)->handle($admin, $target, accountChanges($target, ['role' => UserRole::Persona])))
        ->toThrow(CannotUpdateUserAccountException::class);

    expect($target->fresh()->role)->toBe($role);
})->with(['a member' => UserRole::User, 'a moderator' => UserRole::Moderator]);

it('never turns a persona back into a member, a moderator or an administrator', function (UserRole $role) {
    $admin = User::factory()->admin()->create();
    $persona = User::factory()->persona()->create();

    expect(fn () => app(UpdateUserAccountAction::class)->handle($admin, $persona, accountChanges($persona, ['role' => $role])))
        ->toThrow(CannotUpdateUserAccountException::class);

    expect($persona->fresh()->role)->toBe(UserRole::Persona);
})->with([UserRole::User, UserRole::Moderator, UserRole::Admin]);

it('gives a persona no password, no new address and no other change', function (array $changes) {
    $admin = User::factory()->admin()->create();
    $persona = User::factory()->persona()->create(['name' => 'Kept Name', 'trust_level' => 10]);
    $email = $persona->email;

    expect(fn () => app(UpdateUserAccountAction::class)->handle($admin, $persona, accountChanges($persona, $changes)))
        ->toThrow(CannotUpdateUserAccountException::class);

    expect($persona->fresh())
        ->password->toBeNull()
        ->email->toBe($email)
        ->email_verified_at->toBeNull()
        ->name->toBe('Kept Name')
        ->trust_level->toBe(10);
})->with([
    'a password' => [['password' => 'a-new-long-password']],
    'a new address' => [['email' => 'real@example.com']],
    'a new name' => [['name' => 'Renamed']],
    'no trust' => [['trusted' => false]],
    'nothing at all' => [[]],
]);

it('decides on the locked row: an account made a persona since the page was drawn is left alone', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $stale = User::query()->findOrFail($user->id);
    User::query()->whereKey($user->id)->update(['role' => UserRole::Persona->value, 'password' => null]);

    expect(fn () => app(UpdateUserAccountAction::class)->handle($admin, $stale, accountChanges($stale, ['role' => UserRole::User, 'password' => 'a-new-long-password'])))
        ->toThrow(CannotUpdateUserAccountException::class);

    expect($user->fresh())->role->toBe(UserRole::Persona)->password->toBeNull();
});

it('reads trust on the locked row, moving the level only across the trusted threshold and only when asked', function (int $current, ?bool $trusted, int $expected) {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['trust_level' => $current]);

    app(UpdateUserAccountAction::class)->handle($admin, $user, accountChanges($user, ['trusted' => $trusted]));

    expect($user->fresh()->trust_level)->toBe($expected);
})->with([
    'nothing asked keeps the level' => [3, null, 3],
    'trusted asked of the untrusted' => [3, true, 10],
    'trusted asked of the already trusted keeps their level' => [15, true, 15],
    'untrusted asked of the trusted' => [15, false, 0],
    'untrusted asked of the already untrusted keeps their level' => [3, false, 3],
]);

it('never puts back a trust level set since the editor read the account', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['trust_level' => 0]);
    $drawn = User::query()->findOrFail($user->id);

    // Marked trusted by someone else after the editor was drawn.
    User::query()->whereKey($user->id)->update(['trust_level' => 10]);

    app(UpdateUserAccountAction::class)->handle($admin, $drawn, accountChanges($drawn, ['name' => 'Renamed']));

    expect($user->fresh())->name->toBe('Renamed')->trust_level->toBe(10);
});
