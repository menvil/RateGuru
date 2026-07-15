<?php

use App\Models\ProjectSettings;
use App\Support\Settings\ProjectSettingsManager;

it('returns fallback project settings when row is missing', function () {
    $settings = app(ProjectSettingsManager::class)->current();

    expect($settings->siteName())->toBe('RateGuru');
    expect($settings->objectSingularName())->toBe('post');
    expect($settings->uploadCtaLabel())->toBe('Upload post');
    expect($settings->feedTitle())->toBe('Latest posts');
    expect($settings->defaultTheme())->toBe('system');
    expect($settings->defaultSort())->toBe('hot');
});

it('returns persisted project settings when available', function () {
    ProjectSettings::factory()->create([
        'site_name' => 'CatGuru',
        'object_singular_name' => 'cat',
        'upload_cta_label' => 'Upload cat',
    ]);

    $manager = app(ProjectSettingsManager::class);
    $manager->flush();
    $settings = $manager->current();

    expect($settings->siteName())->toBe('CatGuru');
    expect($settings->objectSingularName())->toBe('cat');
    expect($settings->uploadCtaLabel())->toBe('Upload cat');
});

it('feature flags default to true for show flags when missing from row', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => [],
    ]);

    $manager = app(ProjectSettingsManager::class);
    $manager->flush();

    expect($manager->featureEnabled('show_comments'))->toBeTrue();
});

it('feature flags from db override defaults', function () {
    ProjectSettings::factory()->create([
        'feature_flags' => ['show_comments' => false],
    ]);

    $manager = app(ProjectSettingsManager::class);
    $manager->flush();

    expect($manager->featureEnabled('show_comments'))->toBeFalse();
});

it('missing settings row does not crash the app', function () {
    expect(fn () => app(ProjectSettingsManager::class)->current())->not->toThrow(Throwable::class);
});
