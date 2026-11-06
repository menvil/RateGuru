<?php

namespace App\Support\TranslationEngine;

/**
 * What the engine accepts as a locale: a language code, optionally followed
 * by script, region or variant subtags — `de`, `pt_BR`, `zh-Hant-TW`.
 *
 * The engine does not decide which languages a project offers; that is its
 * consumers' business. It only refuses a string no provider could read as a
 * language, and recognises two spellings of the same locale as one.
 */
final class TranslationLocale
{
    private const PATTERN = '/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,3}$/';

    public static function isValid(string $locale): bool
    {
        return preg_match(self::PATTERN, $locale) === 1;
    }

    /** `pt_BR` and `pt-br` are the same locale. */
    public static function same(string $first, string $second): bool
    {
        return self::normalized($first) === self::normalized($second);
    }

    private static function normalized(string $locale): string
    {
        return strtolower(str_replace('_', '-', $locale));
    }
}
