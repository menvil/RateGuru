<?php

use App\Models\ProjectSettings;
use Database\Seeders\DefaultProjectSettingsSeeder;

it('seeds default project settings', function () {
    $this->seed(DefaultProjectSettingsSeeder::class);

    $settings = ProjectSettings::first();

    expect($settings)->not->toBeNull();
    expect($settings->site_name)->toBe('RateGuru');
    expect($settings->active_preset_key)->toBe('generic');
});

it('seeds default project settings idempotently', function () {
    $this->seed(DefaultProjectSettingsSeeder::class);
    $this->seed(DefaultProjectSettingsSeeder::class);

    expect(ProjectSettings::count())->toBe(1);
});

it('does not overwrite an installation preset', function () {
    ProjectSettings::factory()->create([
        'site_name' => 'NatureGuru',
        'active_preset_key' => 'nature',
        'preset_applied_at' => now(),
    ]);

    $this->seed(DefaultProjectSettingsSeeder::class);

    expect(ProjectSettings::firstOrFail()->site_name)->toBe('NatureGuru')
        ->and(ProjectSettings::firstOrFail()->active_preset_key)->toBe('nature');
});
