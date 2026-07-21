<?php

use App\Filament\Pages\ProjectSettingsPage;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

it('admin can apply nature preset from settings page', function () {
    $admin = User::factory()->admin()->create();

    ProjectSettings::factory()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->call('applyPreset', 'nature')
        ->assertHasNoErrors();

    expect(ProjectSettings::first()->site_name)->toBe('NatureGuru');
    expect(ProjectSettings::first()->active_preset_key)->toBe('nature');
});

it('admin can apply AI images preset from settings page', function () {
    $admin = User::factory()->admin()->create();

    ProjectSettings::factory()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->call('applyPreset', 'ai_images')
        ->assertHasNoErrors();

    expect(ProjectSettings::first()->site_name)->toBe('AIGuru');
    expect(ProjectSettings::first()->active_preset_key)->toBe('ai_images');
});

it('does not apply unknown preset from settings page', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettingsPage::class)
        ->call('applyPreset', 'unknown')
        ->assertHasNoErrors()
        ->assertNotified('Unknown project preset: [unknown].');
});
