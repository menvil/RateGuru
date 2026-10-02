<?php

use Illuminate\Support\Facades\File;

it('has theme config with supported preferences', function () {
    expect(config('themes.preferences'))->toContain('system');
    expect(config('themes.preferences'))->toContain('light');
    expect(config('themes.preferences'))->toContain('dark');
    expect(config('themes.default'))->toBe('system');
});

it('has theme config with applied themes', function () {
    expect(config('themes.applied'))->toContain('light');
    expect(config('themes.applied'))->toContain('dark');
    expect(config('themes.applied'))->not->toContain('system');
});

it('has exactly one owner for the project default theme', function () {
    // ProjectSettings.default_theme is the canonical product setting, and
    // ThemeManager::defaultPreference() reads it first. config('themes.default')
    // is reached only when that setting is absent or invalid.
    //
    // It used to be an env override as well, which gave one concept two owners:
    // a THEME_DEFAULT in a target's .env and a default_theme in the database,
    // disagreeing the first time either was edited. The fallback is now static.
    expect(File::get(base_path('config/themes.php')))
        ->toContain("'default' => 'system',")
        ->not->toContain('THEME_DEFAULT')
        ->not->toContain("env('THEME_DEFAULT'");

    // And it is no longer part of the deployed environment contract at all —
    // removed rather than carried as an excluded knob, since a knob nobody reads
    // is a knob somebody will eventually set and wonder why nothing happened.
    expect(File::get(base_path('infrastructure/config/environment-contract.json')))
        ->not->toContain('THEME_DEFAULT');

    foreach (['staging', 'tits-guru'] as $target) {
        expect(File::get(base_path("infrastructure/templates/environment/{$target}.env.example")))
            ->not->toContain('THEME_DEFAULT');
    }
});
