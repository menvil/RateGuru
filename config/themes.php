<?php

return [
    // Deliberately static, and deliberately not an environment override.
    //
    // ProjectSettings.default_theme is the canonical owner of a project's default
    // theme, and ThemeManager::defaultPreference() reads it first. This value is
    // reached only when that setting is absent or invalid — a defensive fallback,
    // not a second place to configure the same thing. An env override here would
    // give one concept two owners that disagree the first time either is edited.
    'default' => 'system',

    'preferences' => [
        'system',
        'light',
        'dark',
    ],

    'applied' => [
        'light',
        'dark',
    ],
];
