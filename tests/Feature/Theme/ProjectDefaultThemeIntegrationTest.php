<?php

use App\Models\ProjectSettings;
use App\Support\Theme\ThemeManager;

it('uses project default theme for guest when no local preference exists', function () {
    ProjectSettings::factory()->create([
        'default_theme' => 'light',
    ]);

    $this->get(route('feed'))
        ->assertSee('data-theme="light"', false);
});

it('normalizes invalid project default theme to system', function () {
    ProjectSettings::factory()->create([
        'default_theme' => 'invalid',
    ]);

    expect(app(ThemeManager::class)->defaultPreference())->toBe('system');
});

it('uses system when project default is system and applies dark as default', function () {
    ProjectSettings::factory()->create([
        'default_theme' => 'system',
    ]);

    $manager = app(ThemeManager::class);

    expect($manager->defaultPreference())->toBe('system');
    expect($manager->appliedThemeFromPreference('system'))->toBe('dark');
});

it('project settings admin page shows theme options', function () {
    $admin = \App\Models\User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin/project-settings')
        ->assertSee('System')
        ->assertSee('Light')
        ->assertSee('Dark');
});
