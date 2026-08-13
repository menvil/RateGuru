<?php

use App\Actions\Moderation\BanUserAction;
use App\Actions\Moderation\LimitUserAction;
use App\Actions\Moderation\MarkUserTrustedAction;
use App\Actions\Moderation\RestoreUserAccessAction;
use App\Actions\Moderation\ShadowbanUserAction;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Livewire\Livewire;

/*
 * Deleted tombstones in the admin panel: clearly labeled, read-only, and
 * unable to be edited back into an active identity through the generic
 * form. All row actions gate on UserPolicy, which refuses tombstone
 * targets wholesale.
 */

it('lists a tombstone with the Deleted badge without crashing the status column', function () {
    $admin = User::factory()->admin()->create();
    $tombstone = User::factory()->tombstoned()->create(['name' => 'Deleted user']);

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$tombstone]);
});

it('hides every moderation and edit action for a tombstone row', function () {
    $admin = User::factory()->admin()->create();
    $tombstone = User::factory()->tombstoned()->create();

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertTableActionHidden('edit', $tombstone)
        ->assertTableActionHidden('ban', $tombstone)
        ->assertTableActionHidden('limit', $tombstone)
        ->assertTableActionHidden('restoreAccess', $tombstone)
        ->assertTableActionHidden('shadowban', $tombstone)
        ->assertTableActionHidden('markTrusted', $tombstone);
});

it('rejects every moderation action invoked directly against a tombstone', function () {
    // Visibility hiding alone is cosmetic — the actions themselves must
    // deny a tombstone target even when invoked directly.
    $admin = User::factory()->admin()->create();
    $tombstone = User::factory()->tombstoned()->create();

    expect(fn () => app(BanUserAction::class)->handle($admin, $tombstone))
        ->toThrow(CannotModerateUserException::class);
    expect(fn () => app(LimitUserAction::class)->handle($admin, $tombstone))
        ->toThrow(CannotModerateUserException::class);
    expect(fn () => app(RestoreUserAccessAction::class)->handle($admin, $tombstone))
        ->toThrow(CannotModerateUserException::class);
    expect(fn () => app(ShadowbanUserAction::class)->handle($admin, $tombstone))
        ->toThrow(CannotModerateUserException::class);
    expect(fn () => app(MarkUserTrustedAction::class)->handle($admin, $tombstone))
        ->toThrow(CannotModerateUserException::class);

    expect($tombstone->fresh()->status)->toBe(UserStatus::Deleted);
});

it('forbids opening the edit page for a tombstone', function () {
    $admin = User::factory()->admin()->create();
    $tombstone = User::factory()->tombstoned()->create();

    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $tombstone->getRouteKey()])
        ->assertForbidden();
});
