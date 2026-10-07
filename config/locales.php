<?php

return [
    // The default language: what a visitor gets when nothing they chose or
    // their browser asks for is offered, and the language every piece of
    // project content is written in first. System policy, not a setting —
    // English, always installed, always offered, never disabled.
    'default' => 'en',

    // The technical fallback: the catalog Laravel falls back to for a missing
    // line. Also English today, but a different job: it keeps a missing
    // catalog line from rendering as its key, while the default decides which
    // language a visitor is served.
    'fallback' => env('APP_FALLBACK_LOCALE', 'en'),

    // Every language the application is installed with: a complete catalog in
    // lang/{code}/ and the metadata to show it. The flag is chosen per
    // language on purpose — a language is not a country, and `en` is neither
    // the US nor the UK by itself.
    //
    // `enabled_by_default` is bootstrap policy, not project state: it decides
    // which languages besides the default a project offers while it has never
    // chosen its own (project_settings.enabled_locales is NULL). A language
    // added in a release ships with it false, so installing it never offers it
    // to the visitors of an existing project; the Languages page enables it.
    // The default language is offered whatever its row says.
    'supported' => [
        'en' => ['label' => 'English', 'native' => 'English', 'flag' => '🇬🇧', 'enabled_by_default' => true],
        'ru' => ['label' => 'Russian', 'native' => 'Русский', 'flag' => '🇷🇺', 'enabled_by_default' => true],
        'bg' => ['label' => 'Bulgarian', 'native' => 'Български', 'flag' => '🇧🇬', 'enabled_by_default' => true],
        'de' => ['label' => 'German', 'native' => 'Deutsch', 'flag' => '🇩🇪', 'enabled_by_default' => false],
        'es' => ['label' => 'Spanish', 'native' => 'Español', 'flag' => '🇪🇸', 'enabled_by_default' => false],
    ],
];
