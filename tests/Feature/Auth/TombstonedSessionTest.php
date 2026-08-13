<?php

use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Actions\Profile\UpdateUserIdentityAction;
use App\Actions\Profile\UpdateUserProfileAction;
use App\Enums\UserStatus;
use App\Exceptions\Profile\CannotUpdateProfileException;
use App\Models\User;

/*
 * Terminal-account enforcement: anonymization can only revoke
 * database-driver session rows, so any session that survives on another
 * backend (file/redis) — simulated here via actingAs — must be
 * force-terminated by middleware on its next request, and the profile
 * mutation boundary must refuse stale in-flight writes regardless.
 */

it('force-logs-out a surviving session of a tombstoned account on its next request', function () {
    $tombstone = User::factory()->tombstoned()->create();

    $this->actingAs($tombstone)
        ->get(route('feed'))
        ->assertRedirect('/');

    $this->assertGuest();
});

it('terminates the session before any profile mutation reaches the controller', function () {
    $tombstone = User::factory()->tombstoned()->create();
    $tombstoneName = $tombstone->name;

    $this->actingAs($tombstone)
        ->patch('/profile', [
            'name' => 'Necromanced Identity',
            'username' => 'necro',
            'email' => 'necro@example.com',
        ])
        ->assertRedirect('/');

    $this->assertGuest();
    expect($tombstone->fresh()->name)->toBe($tombstoneName);
});

it('does not disturb sessions of living accounts', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('feed'))
        ->assertOk();

    $this->assertAuthenticatedAs($user);
});

/*
 * The race the middleware cannot catch: a request already past auth when
 * the anonymization commits. The mutation boundary itself must re-read
 * under lock and refuse.
 */

it('refuses a stale identity update racing a committed anonymization', function () {
    $user = User::factory()->create();
    $stale = User::query()->findOrFail($user->id);

    app(AnonymizeUserAccountAction::class)->execute($user);
    $tombstoneName = $user->fresh()->name;
    $tombstoneEmail = $user->fresh()->email;

    expect(fn () => app(UpdateUserIdentityAction::class)->execute($stale, [
        'name' => 'Necromanced Identity',
        'username' => 'necro',
        'email' => 'necro@example.com',
    ]))->toThrow(CannotUpdateProfileException::class);

    $fresh = $user->fresh();
    expect($fresh->name)->toBe($tombstoneName)
        ->and($fresh->email)->toBe($tombstoneEmail)
        ->and($fresh->status)->toBe(UserStatus::Deleted);
});

it('refuses a stale profile update racing a committed anonymization', function () {
    $user = User::factory()->create();
    $stale = User::query()->findOrFail($user->id);

    app(AnonymizeUserAccountAction::class)->execute($user);

    expect(fn () => app(UpdateUserProfileAction::class)->execute($stale, [
        'display_name' => 'Necromanced Display',
        'bio' => 'Back from the dead',
    ], null))->toThrow(CannotUpdateProfileException::class);

    $fresh = $user->fresh();
    expect($fresh->display_name)->toBeNull()
        ->and($fresh->bio)->toBeNull()
        ->and($fresh->avatar_asset_id)->toBeNull()
        ->and($fresh->status)->toBe(UserStatus::Deleted);
});

it('still allows living accounts to update identity and profile', function () {
    $user = User::factory()->create();

    app(UpdateUserIdentityAction::class)->execute($user, [
        'name' => 'Renamed User',
        'username' => 'renamed_user_'.$user->id,
        'email' => 'renamed'.$user->id.'@example.com',
    ]);

    app(UpdateUserProfileAction::class)->execute($user, [
        'display_name' => 'Fresh Display',
        'bio' => 'Fresh bio',
        'rating_activity_visibility' => 'private',
    ], null);

    $fresh = $user->fresh();
    expect($fresh->name)->toBe('Renamed User')
        ->and($fresh->display_name)->toBe('Fresh Display')
        ->and($fresh->bio)->toBe('Fresh bio');
});
