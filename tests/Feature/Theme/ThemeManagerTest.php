<?php

use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Theme\ThemeManager;

it('resolves user theme preference before project default', function () {
    $user = User::factory()->create([
        'theme_preference' => 'dark',
    ]);

    ProjectSettings::factory()->create([
        'default_theme' => 'light',
    ]);

    $preference = app(ThemeManager::class)->preferenceForUser($user);

    expect($preference)->toBe('dark');
});

it('falls back to project default theme when user preference is missing', function () {
    $user = User::factory()->create([
        'theme_preference' => null,
    ]);

    ProjectSettings::factory()->create([
        'default_theme' => 'light',
    ]);

    expect(app(ThemeManager::class)->preferenceForUser($user))->toBe('light');
});

it('normalizes invalid preference to system', function () {
    expect(app(ThemeManager::class)->normalizePreference('neon'))->toBe('system');
    expect(app(ThemeManager::class)->normalizePreference(null))->toBe('system');
});

it('resolves applied theme from system preference', function () {
    $manager = app(ThemeManager::class);

    expect($manager->appliedThemeFromPreference('system', 'dark'))->toBe('dark');
    expect($manager->appliedThemeFromPreference('system', 'light'))->toBe('light');
    expect($manager->appliedThemeFromPreference('system', null))->toBe('dark');
});

it('resolves applied theme from explicit preference', function () {
    $manager = app(ThemeManager::class);

    expect($manager->appliedThemeFromPreference('light'))->toBe('light');
    expect($manager->appliedThemeFromPreference('dark'))->toBe('dark');
});

it('falls back to config default when no project settings exist', function () {
    expect(app(ThemeManager::class)->defaultPreference())->toBe('system');
});
