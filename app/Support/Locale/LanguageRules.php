<?php

namespace App\Support\Locale;

/**
 * What the framework's own tables need to be told about installed languages
 * they do not cover by their code. Laravel picks a plural form, and Carbon
 * writes "2 hours ago", from tables keyed by locale: Montenegrin (cnr) is in
 * neither, so it takes Serbian's plural rule and Carbon's Montenegrin Latin
 * dates; and Carbon's plain "sr" is Serbian in Latin script, while the
 * installed Serbian is Cyrillic.
 *
 * A language Laravel has no plural rule for gets the first form for every
 * number — "1 komentar" for five comments — and nothing says so; the tests
 * hold every installed language to a known rule, here or in Laravel.
 */
final class LanguageRules
{
    /** Installed languages that take another locale's plural rule, by code. */
    public const PLURAL_RULES = [
        'cnr' => 'sr',
    ];

    /** Installed languages whose dates Carbon writes under another locale, by code. */
    public const DATE_LOCALES = [
        'sr' => 'sr_Cyrl',
        'cnr' => 'sr_Latn_ME',
    ];

    public static function pluralLocale(string $locale): string
    {
        return self::PLURAL_RULES[$locale] ?? $locale;
    }

    public static function dateLocale(string $locale): string
    {
        return self::DATE_LOCALES[$locale] ?? $locale;
    }
}
