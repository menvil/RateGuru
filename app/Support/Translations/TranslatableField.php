<?php

namespace App\Support\Translations;

class TranslatableField
{
    /**
     * The language a translatable model's base column holds: `name` is the
     * English name, `name_translations` the other languages.
     */
    public const REFERENCE_LOCALE = 'en';

    /**
     * Whether a stored value counts as a translation: any string with
     * something in it. Null, empty, whitespace and anything that is not a
     * string do not — and nothing here judges whether the text is good.
     */
    public static function isPresent(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    public static function resolve(
        mixed $translations,
        string $fallback,
        ?string $locale = null
    ): string {
        $locale ??= app()->getLocale();

        if (is_array($translations) && isset($translations[$locale]) && $translations[$locale] !== '') {
            return $translations[$locale];
        }

        return $fallback;
    }
}
