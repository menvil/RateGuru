<?php

namespace App\Support\Translations;

class TranslatableField
{
    public static function resolve(
        mixed $translations,
        string $fallback,
        ?string $locale = null
    ): string {
        $locale ??= app()->getLocale();

        if (is_array($translations) && ! empty($translations[$locale])) {
            return $translations[$locale];
        }

        return $fallback;
    }
}
