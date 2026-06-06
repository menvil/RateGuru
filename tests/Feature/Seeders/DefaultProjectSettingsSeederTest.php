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
