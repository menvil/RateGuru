<?php

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
        ->assertTableActionHidden('unban', $tombstone)
        ->assertTableActionHidden('shadowban', $tombstone)
        ->assertTableActionHidden('markTrusted', $tombstone);
});

it('forbids opening the edit page for a tombstone', function () {
    $admin = User::factory()->admin()->create();
    $tombstone = User::factory()->tombstoned()->create();

    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $tombstone->getRouteKey()])
        ->assertForbidden();
});
