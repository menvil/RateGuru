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
