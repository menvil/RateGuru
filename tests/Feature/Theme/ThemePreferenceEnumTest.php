<?php

use App\Enums\ThemePreference;

it('validates supported theme preferences', function () {
    expect(ThemePreference::isValid('system'))->toBeTrue();
    expect(ThemePreference::isValid('light'))->toBeTrue();
    expect(ThemePreference::isValid('dark'))->toBeTrue();
    expect(ThemePreference::isValid('neon'))->toBeFalse();
});

it('has correct enum cases', function () {
    expect(ThemePreference::System->value)->toBe('system');
    expect(ThemePreference::Light->value)->toBe('light');
    expect(ThemePreference::Dark->value)->toBe('dark');
});

it('falls back to default for invalid preference string', function () {
    $result = ThemePreference::fromStringOrDefault('invalid');

    expect($result)->toBe(ThemePreference::System);
});

it('returns enum for valid preference string', function () {
    $result = ThemePreference::fromStringOrDefault('light');

    expect($result)->toBe(ThemePreference::Light);
});
