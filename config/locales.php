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
        'fr' => ['label' => 'French', 'native' => 'Français', 'flag' => '🇫🇷', 'enabled_by_default' => false],
        'it' => ['label' => 'Italian', 'native' => 'Italiano', 'flag' => '🇮🇹', 'enabled_by_default' => false],
        'pt' => ['label' => 'Portuguese (Brazil)', 'native' => 'Português (Brasil)', 'flag' => '🇧🇷', 'enabled_by_default' => false],
        'tr' => ['label' => 'Turkish', 'native' => 'Türkçe', 'flag' => '🇹🇷', 'enabled_by_default' => false],
        'ja' => ['label' => 'Japanese', 'native' => '日本語', 'flag' => '🇯🇵', 'enabled_by_default' => false],
        'pl' => ['label' => 'Polish', 'native' => 'Polski', 'flag' => '🇵🇱', 'enabled_by_default' => false],
        'ro' => ['label' => 'Romanian', 'native' => 'Română', 'flag' => '🇷🇴', 'enabled_by_default' => false],
        'el' => ['label' => 'Greek', 'native' => 'Ελληνικά', 'flag' => '🇬🇷', 'enabled_by_default' => false],
        'nl' => ['label' => 'Dutch', 'native' => 'Nederlands', 'flag' => '🇳🇱', 'enabled_by_default' => false],
        'hu' => ['label' => 'Hungarian', 'native' => 'Magyar', 'flag' => '🇭🇺', 'enabled_by_default' => false],
        'cs' => ['label' => 'Czech', 'native' => 'Čeština', 'flag' => '🇨🇿', 'enabled_by_default' => false],
        'sk' => ['label' => 'Slovak', 'native' => 'Slovenčina', 'flag' => '🇸🇰', 'enabled_by_default' => false],
        'sr' => ['label' => 'Serbian', 'native' => 'Српски', 'flag' => '🇷🇸', 'enabled_by_default' => false],
        'hr' => ['label' => 'Croatian', 'native' => 'Hrvatski', 'flag' => '🇭🇷', 'enabled_by_default' => false],
        'sl' => ['label' => 'Slovenian', 'native' => 'Slovenščina', 'flag' => '🇸🇮', 'enabled_by_default' => false],
        'fi' => ['label' => 'Finnish', 'native' => 'Suomi', 'flag' => '🇫🇮', 'enabled_by_default' => false],
        'sv' => ['label' => 'Swedish', 'native' => 'Svenska', 'flag' => '🇸🇪', 'enabled_by_default' => false],
        'nb' => ['label' => 'Norwegian', 'native' => 'Norsk bokmål', 'flag' => '🇳🇴', 'enabled_by_default' => false],
        'da' => ['label' => 'Danish', 'native' => 'Dansk', 'flag' => '🇩🇰', 'enabled_by_default' => false],
        'et' => ['label' => 'Estonian', 'native' => 'Eesti', 'flag' => '🇪🇪', 'enabled_by_default' => false],
        'lt' => ['label' => 'Lithuanian', 'native' => 'Lietuvių', 'flag' => '🇱🇹', 'enabled_by_default' => false],
        'lv' => ['label' => 'Latvian', 'native' => 'Latviešu', 'flag' => '🇱🇻', 'enabled_by_default' => false],
        'uk' => ['label' => 'Ukrainian', 'native' => 'Українська', 'flag' => '🇺🇦', 'enabled_by_default' => false],
        'zh' => ['label' => 'Chinese (Simplified)', 'native' => '简体中文', 'flag' => '🇨🇳', 'enabled_by_default' => false],
        'ka' => ['label' => 'Georgian', 'native' => 'ქართული', 'flag' => '🇬🇪', 'enabled_by_default' => false],
        'is' => ['label' => 'Icelandic', 'native' => 'Íslenska', 'flag' => '🇮🇸', 'enabled_by_default' => false],
        'th' => ['label' => 'Thai', 'native' => 'ไทย', 'flag' => '🇹🇭', 'enabled_by_default' => false],
        'vi' => ['label' => 'Vietnamese', 'native' => 'Tiếng Việt', 'flag' => '🇻🇳', 'enabled_by_default' => false],
        'fil' => ['label' => 'Filipino', 'native' => 'Filipino', 'flag' => '🇵🇭', 'enabled_by_default' => false],
        'ms' => ['label' => 'Malay', 'native' => 'Bahasa Melayu', 'flag' => '🇲🇾', 'enabled_by_default' => false],
        'cnr' => ['label' => 'Montenegrin', 'native' => 'Crnogorski', 'flag' => '🇲🇪', 'enabled_by_default' => false],
        'bs' => ['label' => 'Bosnian', 'native' => 'Bosanski', 'flag' => '🇧🇦', 'enabled_by_default' => false],
        'mk' => ['label' => 'Macedonian', 'native' => 'Македонски', 'flag' => '🇲🇰', 'enabled_by_default' => false],
        'sq' => ['label' => 'Albanian', 'native' => 'Shqip', 'flag' => '🇦🇱', 'enabled_by_default' => false],
    ],
];
