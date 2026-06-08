<?php

use App\Filament\Pages\ProjectSettingsPage;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

it('admin can apply cats preset from settings page', function () {
    $admin = User::factory()->admin()->create();

    ProjectSettings::factory()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->call('applyPreset', 'cats')
        ->assertHasNoErrors();

    expect(ProjectSettings::first()->site_name)->toBe('CatGuru');
    expect(ProjectSettings::first()->active_preset_key)->toBe('cats');
});

it('admin can apply food preset from settings page', function () {
    $admin = User::factory()->admin()->create();

    ProjectSettings::factory()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->call('applyPreset', 'food')
        ->assertHasNoErrors();

    expect(ProjectSettings::first()->site_name)->toBe('FoodGuru');
    expect(ProjectSettings::first()->active_preset_key)->toBe('food');
});

it('does not apply unknown preset from settings page', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->call('applyPreset', 'unknown')
        ->assertHasNoErrors()
        ->assertNotified('Unknown project preset: [unknown].');
});
