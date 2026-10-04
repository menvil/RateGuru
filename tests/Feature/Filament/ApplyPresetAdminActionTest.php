<?php

use App\Filament\Pages\ProjectSettingsPage;
use App\Models\ProjectSettings;
use App\Models\User;
use Illuminate\Support\Carbon;

it('does not expose preset application controls in project settings', function () {
    $admin = User::factory()->admin()->create();
    ProjectSettings::factory()->create();

    $this->actingAs($admin)
        ->get('/admin/project-settings')
        ->assertOk()
        ->assertDontSee('Apply a preset')
        ->assertDontSee('wire:click="applyPreset', false);

    expect(method_exists(ProjectSettingsPage::class, 'applyPreset'))->toBeFalse();
});

it('shows the installed preset and application time as read-only information', function () {
    $admin = User::factory()->admin()->create();
    ProjectSettings::factory()->create([
        'active_preset_key' => 'nature',
        'preset_applied_at' => Carbon::parse('2026-07-22 10:00:00'),
    ]);

    $this->actingAs($admin)
        ->get('/admin/project-settings')
        ->assertOk()
        ->assertSee('Installation preset')
        ->assertSee('Nature &amp; travel photography', false)
        ->assertSee('2026-07-22 10:00:00');
});

it('shows the setup command when no installation preset was applied', function () {
    $admin = User::factory()->admin()->create();
    ProjectSettings::factory()->create(['preset_applied_at' => null]);

    $this->actingAs($admin)
        ->get('/admin/project-settings')
        ->assertOk()
        ->assertSee('No installation preset has been applied.')
        ->assertSee('php artisan rateguru:setup');
});
