<?php

use App\Filament\Resources\RatingGroups\Pages\EditRatingGroup;
use App\Filament\Resources\RatingGroups\RelationManagers\OptionsRelationManager;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;
use Livewire\Livewire;

it('allows admin to create a rating option for a group', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create();

    $this->actingAs($admin);

    Livewire::test(OptionsRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => EditRatingGroup::class,
    ])
        ->callTableAction('create', data: [
            'key' => 'option_a',
            'label' => 'Option A',
            'description' => 'First option.',
            'is_active' => true,
            'sort_order' => 10,
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('rating_options', [
        'rating_group_id' => $group->id,
        'key' => 'option_a',
        'label' => 'Option A',
        'is_active' => true,
        'sort_order' => 10,
    ]);
});

it('allows admin to edit a rating option', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create(['min_options' => 2]);
    $option = RatingOption::factory()->for($group, 'group')->create();
    RatingOption::factory()->count(2)->for($group, 'group')->create();

    $this->actingAs($admin);

    Livewire::test(OptionsRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => EditRatingGroup::class,
    ])
        ->callTableAction('edit', $option, data: [
            'key' => 'updated_option',
            'label' => 'Updated Option',
            'description' => 'Updated description.',
            'is_active' => false,
            'sort_order' => 30,
        ])
        ->assertHasNoTableActionErrors();

    $fresh = $option->fresh();

    expect($fresh->key)->toBe('updated_option')
        ->and($fresh->label)->toBe('Updated Option')
        ->and($fresh->description)->toBe('Updated description.')
        ->and($fresh->is_active)->toBeFalse()
        ->and($fresh->sort_order)->toBe(30);
});

it('enforces option key uniqueness within a group', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create();
    $otherGroup = RatingGroup::factory()->create();

    RatingOption::factory()->for($group, 'group')->create(['key' => 'shared_key']);
    RatingOption::factory()->for($otherGroup, 'group')->create(['key' => 'shared_key']);

    $this->actingAs($admin);

    Livewire::test(OptionsRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => EditRatingGroup::class,
    ])
        ->callTableAction('create', data: [
            'key' => 'shared_key',
            'label' => 'Duplicate',
            'is_active' => true,
            'sort_order' => 20,
        ])
        ->assertHasTableActionErrors(['key' => 'unique']);
});

it('does not allow active options above the group maximum', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create([
        'min_options' => 2,
        'max_options' => 2,
    ]);

    RatingOption::factory()->count(2)->for($group, 'group')->create(['is_active' => true]);

    $this->actingAs($admin);

    Livewire::test(OptionsRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => EditRatingGroup::class,
    ])
        ->callTableAction('create', data: [
            'key' => 'too_many',
            'label' => 'Too Many',
            'is_active' => true,
            'sort_order' => 30,
        ])
        ->assertHasTableActionErrors(['is_active']);

    expect($group->options()->count())->toBe(2);
});

it('does not allow disabling an option below the group minimum', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create([
        'min_options' => 2,
        'max_options' => 10,
    ]);
    [$first] = RatingOption::factory()->count(2)->for($group, 'group')->create([
        'is_active' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(OptionsRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => EditRatingGroup::class,
    ])
        ->callTableAction('edit', $first, data: [
            'key' => $first->key,
            'label' => $first->label,
            'description' => $first->description,
            'is_active' => false,
            'sort_order' => $first->sort_order,
        ])
        ->assertHasTableActionErrors(['is_active']);

    expect($first->fresh()->is_active)->toBeTrue();
});

it('shows vote counts for rating options', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create();
    $option = RatingOption::factory()->for($group, 'group')->create();

    RatingVote::factory()
        ->count(2)
        ->for($group, 'group')
        ->for($option, 'option')
        ->create();

    $this->actingAs($admin);

    Livewire::test(OptionsRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => EditRatingGroup::class,
    ])
        ->assertCanSeeTableRecords([$option])
        ->assertTableColumnStateSet('votes_count', 2, record: $option);
});
