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
    return ['name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'role' => $user->role, 'trust_level' => (int) $user->trust_level, ...$changes];
}

it('saves the identity, the role, the trust and a new password, and leaves the status alone', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['status' => UserStatus::Limited]);
    $before = $user->password;

    app(UpdateUserAccountAction::class)->handle($admin, $user, accountChanges($user, [
        'name' => 'New Name', 'username' => 'new_name', 'role' => UserRole::Moderator, 'trust_level' => 0, 'password' => 'a-new-long-password',
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
