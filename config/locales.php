<?php

return [
    // The technical fallback: the catalog Laravel falls back to for a missing
    // line, and the emergency locale when nothing else resolves. It must be
    // installed, but a project does not have to offer it — which languages a
    // project offers and which one new visitors get are project settings
    // (enabled_locales, default_locale), read through LocaleManager.
    'fallback' => env('APP_FALLBACK_LOCALE', 'en'),

    // Every language the application is installed with: a complete catalog in
    // lang/{code}/ and the metadata to show it. The flag is chosen per
    // language on purpose — a language is not a country, and `en` is neither
    // the US nor the UK by itself.
    'supported' => [
        'en' => ['label' => 'English', 'native' => 'English', 'flag' => '🇬🇧'],
        'ru' => ['label' => 'Russian', 'native' => 'Русский', 'flag' => '🇷🇺'],
        'bg' => ['label' => 'Bulgarian', 'native' => 'Български', 'flag' => '🇧🇬'],
    ],
];
