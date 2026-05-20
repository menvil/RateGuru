<?php

use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Livewire\Livewire;

it('filters active users in user resource', function () {
    $admin = User::factory()->admin()->create();
    $active = User::factory()->create([
        'status' => UserStatus::Active,
        'username' => 'active_user',
    ]);
    $banned = User::factory()->banned()->create([
        'username' => 'banned_user',
    ]);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->filterTable('active')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$banned]);
});

it('filters banned users in user resource', function () {
    $admin = User::factory()->admin()->create();
    $banned = User::factory()->banned()->create([
        'username' => 'banned_user',
    ]);
    $active = User::factory()->create([
        'status' => UserStatus::Active,
        'username' => 'active_user',
    ]);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->filterTable('banned')
        ->assertCanSeeTableRecords([$banned])
        ->assertCanNotSeeTableRecords([$active]);
});

it('filters trusted users in user resource', function () {
    $admin = User::factory()->admin()->create();
    $trusted = User::factory()->trusted()->create([
        'username' => 'trusted_user',
    ]);
    $active = User::factory()->create([
        'status' => UserStatus::Active,
        'trust_level' => 0,
        'username' => 'active_user',
    ]);
    // A high trust_level on a non-active user must not match: the filter
    // requires both trust_level >= TRUSTED_LEVEL *and* status = Active.
    $bannedButHighTrust = User::factory()->create([
        'status' => UserStatus::Banned,
        'trust_level' => 10,
        'username' => 'banned_high_trust',
    ]);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->filterTable('trusted')
        ->assertCanSeeTableRecords([$trusted])
        ->assertCanNotSeeTableRecords([$active, $bannedButHighTrust]);
});
