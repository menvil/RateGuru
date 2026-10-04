<?php

use App\Models\ProjectSettings;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;
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

it('never reseeds the languages an existing project offers', function () {
    [, $only] = twoTranslatedLocales();
    ProjectSettings::factory()->create();
    offerLocales([$only]);
    $offered = ProjectSettings::firstOrFail()->enabled_locales;

    $this->seed(DefaultProjectSettingsSeeder::class);

    expect(ProjectSettings::firstOrFail()->enabled_locales)->toBe($offered);
});

it('gives a fresh database the languages enabled by default, with English the default', function () {
    $this->seed(DefaultProjectSettingsSeeder::class);

    expect(ProjectSettings::firstOrFail()->enabled_locales)->toBeNull()
        ->and(app(LocaleManager::class)->enabledCodes())->toBe(app(LocaleManager::class)->enabledByDefault())
        ->and(app(LocaleManager::class)->default())->toBe('en');
});

it('creates the row from the same bootstrap every other writer uses', function () {
    $this->seed(DefaultProjectSettingsSeeder::class);

    $defaults = app(ProjectSettingsManager::class)->defaults();
    $settings = ProjectSettings::firstOrFail();

    foreach (['site_name', 'site_tagline', 'object_singular_name', 'object_plural_name', 'upload_cta_label', 'feed_title', 'default_theme', 'default_sort', 'active_preset_key', 'feature_flags', 'static_pages'] as $column) {
        expect($settings->getAttribute($column))->toBe($defaults[$column], $column);
    }
});
