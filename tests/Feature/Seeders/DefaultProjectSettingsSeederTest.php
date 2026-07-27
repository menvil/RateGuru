<?php

use App\Models\ProjectSettings;
use Database\Seeders\DefaultProjectSettingsSeeder;

it('seeds default project settings', function () {
    $this->seed(DefaultProjectSettingsSeeder::class);

    $settings = ProjectSettings::first();

    expect($settings)->not->toBeNull();
    expect($settings->site_name)->toBe('RateGuru');
    expect($settings->active_preset_key)->toBe('generic');
    expect($settings->static_pages)->toBe(config('static-pages.defaults'));
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

it('preserves administrator edited static pages on subsequent seed runs', function () {
    $staticPages = config('static-pages.defaults');
    $staticPages['about']['en'] = [
        'title' => 'Administrator title',
        'content' => 'Administrator content',
    ];

    ProjectSettings::factory()->create([
        'site_name' => 'Existing site name',
        'static_pages' => $staticPages,
    ]);

    $this->seed(DefaultProjectSettingsSeeder::class);

    $settings = ProjectSettings::firstOrFail();

    expect($settings->site_name)->toBe('RateGuru')
        ->and($settings->static_pages)->toBe($staticPages);
});
