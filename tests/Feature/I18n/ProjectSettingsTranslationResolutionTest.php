<?php

use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;

it('returns translated project setting for current locale', function () {
    ProjectSettings::factory()->create([
        'site_name' => 'RateGuru',
        'site_name_translations' => ['ru' => 'РейтГуру'],
    ]);

    app()->setLocale('ru');

    expect(app(ProjectSettingsManager::class)->current()->siteName())->toBe('РейтГуру');
});

it('falls back to base project setting when translation is missing', function () {
    ProjectSettings::factory()->create([
        'site_name' => 'RateGuru',
        'site_name_translations' => ['ru' => ''],
    ]);

    app()->setLocale('bg');

    expect(app(ProjectSettingsManager::class)->current()->siteName())->toBe('RateGuru');
});

it('falls back to base setting when translations column is null', function () {
    ProjectSettings::factory()->create([
        'feed_title' => 'Latest posts',
        'feed_title_translations' => null,
    ]);

    app()->setLocale('ru');

    expect(app(ProjectSettingsManager::class)->current()->feedTitle())->toBe('Latest posts');
});

it('returns translated feed title for current locale', function () {
    ProjectSettings::factory()->create([
        'feed_title' => 'Latest posts',
        'feed_title_translations' => ['ru' => 'Последние посты'],
    ]);

    app()->setLocale('ru');

    expect(app(ProjectSettingsManager::class)->current()->feedTitle())->toBe('Последние посты');
});
