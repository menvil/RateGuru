<?php

use App\Models\ProjectSettings;
use App\Support\Locale\LocaleManager;
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

it('never reseeds the languages an existing project offers or its default', function () {
    [, $only] = twoTranslatedLocales();
    ProjectSettings::factory()->create();
    offerLocales([$only], $only);

    $this->seed(DefaultProjectSettingsSeeder::class);

    expect(ProjectSettings::firstOrFail())
        ->enabled_locales->toBe([$only])
        ->default_locale->toBe($only);
});

it('gives a fresh database every installed language and an installed default', function () {
    $this->seed(DefaultProjectSettingsSeeder::class);

    $settings = ProjectSettings::firstOrFail();

    expect($settings->enabled_locales)->toBeNull()
        ->and($settings->default_locale)->toBe(config('locales.fallback'))
        ->and(app(LocaleManager::class)->isEnabled($settings->default_locale))->toBeTrue();
});
