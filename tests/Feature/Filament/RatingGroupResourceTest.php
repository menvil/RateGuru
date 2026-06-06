<?php

use App\Filament\Resources\RatingGroups\Pages\CreateRatingGroup;
use App\Filament\Resources\RatingGroups\Pages\EditRatingGroup;
use App\Filament\Resources\RatingGroups\RatingGroupResource;
use App\Models\RatingGroup;
use App\Models\User;
use Livewire\Livewire;

it('allows admin to access rating groups resource index', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(RatingGroupResource::getUrl('index'))
        ->assertOk();
});

it('does not allow a normal user to access rating groups resource index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(RatingGroupResource::getUrl('index'))
        ->assertForbidden();
});

it('does not allow a moderator to manage rating groups', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(RatingGroupResource::getUrl('index'))
        ->assertForbidden();
});

it('allows admin to create a rating group', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(CreateRatingGroup::class)
        ->fillForm([
            'key' => 'real_or_fake',
            'label' => 'Real or Fake',
            'description' => 'Classify the post.',
            'min_options' => 2,
            'max_options' => 4,
            'is_active' => true,
            'sort_order' => 30,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('rating_groups', [
        'key' => 'real_or_fake',
        'label' => 'Real or Fake',
        'min_options' => 2,
        'max_options' => 4,
        'is_active' => true,
        'sort_order' => 30,
    ]);
});

it('allows admin to edit rating group fields', function () {
    $admin = User::factory()->admin()->create();
    $group = RatingGroup::factory()->create();

    $this->actingAs($admin);

    Livewire::test(EditRatingGroup::class, ['record' => $group->getRouteKey()])
        ->fillForm([
            'key' => 'updated_group',
            'label' => 'Updated Group',
            'description' => 'Updated description.',
            'min_options' => 3,
            'max_options' => 8,
            'is_active' => false,
            'sort_order' => 40,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $group->fresh();

    expect($fresh->key)->toBe('updated_group')
        ->and($fresh->label)->toBe('Updated Group')
        ->and($fresh->description)->toBe('Updated description.')
        ->and($fresh->min_options)->toBe(3)
        ->and($fresh->max_options)->toBe(8)
        ->and($fresh->is_active)->toBeFalse()
        ->and($fresh->sort_order)->toBe(40);
});

it('validates rating group key format and option range', function () {
    $admin = User::factory()->admin()->create();

    RatingGroup::factory()->create(['key' => 'existing_group']);

    $this->actingAs($admin);

    Livewire::test(CreateRatingGroup::class)
        ->fillForm([
            'key' => 'existing group',
            'label' => '',
            'min_options' => 5,
            'max_options' => 4,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'key' => 'alpha_dash',
            'label' => 'required',
            'min_options' => 'lte',
            'max_options' => 'gte',
        ]);
});

it('requires unique rating group keys', function () {
    $admin = User::factory()->admin()->create();

    RatingGroup::factory()->create(['key' => 'existing_group']);

    $this->actingAs($admin);

    Livewire::test(CreateRatingGroup::class)
        ->fillForm([
            'key' => 'existing_group',
            'label' => 'Duplicate group',
            'min_options' => 2,
            'max_options' => 10,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['key' => 'unique']);
});
