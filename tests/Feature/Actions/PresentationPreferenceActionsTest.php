<?php

use App\Actions\Settings\SaveProjectSettingsAction;
use App\Actions\Users\UpdateNotificationPreferencesAction;
use App\Actions\Users\UpdateThemePreferenceAction;
use App\Actions\Users\UpdateUserLocaleAction;
use App\Enums\ThemePreference;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;

it('updates a user theme preference', function () {
    $user = User::factory()->create(['theme_preference' => null]);

    app(UpdateThemePreferenceAction::class)->handle($user, ThemePreference::Dark);

    expect($user->fresh()->theme_preference)->toBe(ThemePreference::Dark->value);
});

it('updates a user notification preference', function () {
    $user = User::factory()->create(['notify_followed_author_posts' => true]);

    app(UpdateNotificationPreferencesAction::class)->handle($user, false);

    expect($user->fresh()->notify_followed_author_posts)->toBeFalse();
});

it('updates a user locale preference', function () {
    $user = User::factory()->create(['locale' => 'en']);

    app(UpdateUserLocaleAction::class)->handle($user, 'ru');

    expect($user->fresh()->locale)->toBe('ru');
});

it('rejects unsupported locales at the action boundary', function () {
    $user = User::factory()->create(['locale' => 'en']);

    expect(fn () => app(UpdateUserLocaleAction::class)->handle($user, 'de'))
        ->toThrow(InvalidArgumentException::class);

    expect($user->fresh()->locale)->toBe('en');
});

it('saves project settings and invalidates the cached settings snapshot', function () {
    ProjectSettings::factory()->create(['site_name' => 'Old name']);
    $manager = app(ProjectSettingsManager::class);
    expect($manager->current()->siteName())->toBe('Old name');

    app(SaveProjectSettingsAction::class)->handle(['site_name' => 'New name']);

    expect(ProjectSettings::findOrFail(1)->site_name)->toBe('New name')
        ->and($manager->current()->siteName())->toBe('New name');
});
