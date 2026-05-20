<?php

use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Livewire\Livewire;

it('allows admin to ban a user via the ban table action', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['status' => UserStatus::Active]);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->callTableAction('ban', $target, data: ['reason' => 'Repeated abuse.']);

    expect($target->fresh()->status)->toBe(UserStatus::Banned);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'target_type' => User::class,
        'target_id' => $target->id,
    ]);
});

it('hides the ban action from moderators', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create(['status' => UserStatus::Active]);

    $this->actingAs($moderator);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('ban', $target);
});

it('hides the ban action for admin targets', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('ban', $otherAdmin);
});

it('hides the ban action for the acting admin themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('ban', $admin);
});

it('hides the ban action for already banned users', function () {
    $admin = User::factory()->admin()->create();
    $banned = User::factory()->banned()->create();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('ban', $banned);
});

it('allows admin to unban a user via the unban table action', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->banned()->create();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->callTableAction('unban', $target, data: ['reason' => 'Appeal accepted.']);

    expect($target->fresh()->status)->toBe(UserStatus::Active);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'target_type' => User::class,
        'target_id' => $target->id,
    ]);
});

it('hides the unban action from moderators', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->banned()->create();

    $this->actingAs($moderator);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('unban', $target);
});

it('hides the unban action for users who are not banned or shadowbanned', function () {
    $admin = User::factory()->admin()->create();
    $active = User::factory()->create(['status' => UserStatus::Active]);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('unban', $active);
});
