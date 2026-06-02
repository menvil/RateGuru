<?php

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Enums\ModerationActionType;
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
        'action' => ModerationActionType::BanUser->value,
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

it('hides the edit action from moderators', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create(['status' => UserStatus::Active]);

    $this->actingAs($moderator);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('edit', $target);
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
        'action' => ModerationActionType::UnbanUser->value,
        'target_type' => User::class,
        'target_id' => $target->id,
    ]);
});

it('allows admin to unban a shadowbanned user via the unban table action', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['status' => UserStatus::Shadowbanned]);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->callTableAction('unban', $target, data: ['reason' => 'Appeal accepted.']);

    expect($target->fresh()->status)->toBe(UserStatus::Active);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'action' => ModerationActionType::UnbanUser->value,
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

it('hides the unban action for the acting admin themselves', function () {
    // Defence in depth: admins cannot be banned via normal flow, but if
    // schema drift puts one in a banned state the self-guard must still
    // prevent the action from rendering. The Banned status here is what
    // exposes the self-guard — otherwise the status branch would hide
    // the action for unrelated reasons and the assertion would pass
    // spuriously.
    $admin = User::factory()->admin()->create(['status' => UserStatus::Banned]);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('unban', $admin);
});

it('hides the unban action for admin targets', function () {
    $admin = User::factory()->admin()->create();
    // Same defence-in-depth: another admin in a banned state must still
    // not be unbannable through this resource.
    $adminTarget = User::factory()->admin()->create(['status' => UserStatus::Banned]);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('unban', $adminTarget);
});

it('allows admin to mark a user trusted via the mark trusted action', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create([
        'status' => UserStatus::Active,
        'trust_level' => 0,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->callTableAction('markTrusted', $target, data: ['reason' => 'Reliable contributor.']);

    $fresh = $target->fresh();
    expect($fresh->trust_level)->toBe(MarkUserTrustedAction::TRUSTED_LEVEL);
    expect($fresh->status)->toBe(UserStatus::Active);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'action' => ModerationActionType::MarkUserTrusted->value,
        'target_type' => User::class,
        'target_id' => $target->id,
        'reason' => 'Reliable contributor.',
    ]);
});

it('hides the mark trusted action from moderators', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create(['status' => UserStatus::Active]);

    $this->actingAs($moderator);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('markTrusted', $target);
});

it('hides the mark trusted action for admin or moderator targets', function () {
    $admin = User::factory()->admin()->create();
    $otherMod = User::factory()->moderator()->create();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('markTrusted', $otherMod);
});

it('hides the mark trusted action for already trusted users', function () {
    $admin = User::factory()->admin()->create();
    $trusted = User::factory()->trusted()->create();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('markTrusted', $trusted);
});

it('allows admin to shadowban a user via the shadowban table action', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['status' => UserStatus::Active]);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->callTableAction('shadowban', $target, data: ['reason' => 'Suspicious behavior.']);

    expect($target->fresh()->status)->toBe(UserStatus::Shadowbanned);

    $this->assertDatabaseHas('moderation_logs', [
        'moderator_id' => $admin->id,
        'action' => ModerationActionType::ShadowbanUser->value,
        'target_type' => User::class,
        'target_id' => $target->id,
    ]);
});

it('hides the shadowban action from moderators', function () {
    $moderator = User::factory()->moderator()->create();
    $target = User::factory()->create(['status' => UserStatus::Active]);

    $this->actingAs($moderator);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('shadowban', $target);
});

it('hides the shadowban action for admin targets', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('shadowban', $otherAdmin);
});

it('hides the shadowban action for the acting admin themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('shadowban', $admin);
});

it('hides the shadowban action for already banned or shadowbanned users', function () {
    $admin = User::factory()->admin()->create();
    $shadowbanned = User::factory()->create(['status' => UserStatus::Shadowbanned]);
    $banned = User::factory()->banned()->create();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('shadowban', $shadowbanned)
        ->assertTableActionHidden('shadowban', $banned);
});
