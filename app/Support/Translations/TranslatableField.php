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
     *
     * The one rule for both sides: what the Languages page counts as missing
     * is exactly what resolve() replaces with the fallback for a visitor.
     *
     * @phpstan-assert-if-true string $value
     */
    public static function isPresent(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * The stored translation for this language when it is one (see
     * isPresent()), otherwise the fallback — never blank text and never a
     * value that is not a string. English is the base column itself: an
     * English entry among the translations is never read, so the text its
     * editor saves is the text a visitor sees.
     */
    public static function resolve(
        mixed $translations,
        string $fallback,
        ?string $locale = null
    ): string {
        $locale ??= app()->getLocale();

        if ($locale === self::REFERENCE_LOCALE) {
            return $fallback;
        }

        $value = is_array($translations) ? ($translations[$locale] ?? null) : null;

        return self::isPresent($value) ? $value : $fallback;
    }

    /**
     * Translations as stored beside a base column: every language but English,
     * which the base column holds — or null when none is left.
     *
     * @return array<string, mixed>|null
     */
    public static function withoutReference(mixed $translations): ?array
    {
        if (! is_array($translations)) {
            return null;
        }

        unset($translations[self::REFERENCE_LOCALE]);

        return $translations === [] ? null : $translations;
    }
}
