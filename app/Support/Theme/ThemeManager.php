<?php

namespace App\Support\Theme;

use App\Enums\ThemePreference;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;

class ThemeManager
{
    public function __construct(private readonly ProjectSettingsManager $settings) {}

    public function preferenceForUser(?User $user): string
    {
        if ($user && $user->theme_preference !== null) {
            return $this->normalizePreference($user->theme_preference);
        }

        return $this->defaultPreference();
    }

    public function defaultPreference(): string
    {
        $setting = $this->settings->current()->defaultTheme();

        return $this->normalizePreference($setting);
    }

    public function normalizePreference(?string $preference): string
    {
        if ($preference !== null && ThemePreference::isValid($preference)) {
            return $preference;
        }

        $configDefault = config('themes.default', 'system');

        return ThemePreference::isValid($configDefault) ? $configDefault : 'system';
    }

    public function appliedThemeFromPreference(string $preference, ?string $systemPreference = null): string
    {
        $normalized = $this->normalizePreference($preference);

        if ($normalized === 'system') {
            return match ($systemPreference) {
                'light' => 'light',
                'dark' => 'dark',
                default => 'dark',
            };
        }

        return $normalized;
    }
}
