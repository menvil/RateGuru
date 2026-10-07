<?php

namespace App\Support\Locale;

/**
 * What others need to be told about installed languages that their code alone
 * does not pin down. Laravel picks a plural form, and Carbon writes "2 hours
 * ago", from tables keyed by locale: Montenegrin (cnr) is in neither, so it
 * takes Serbian's plural rule and Carbon's Montenegrin Latin dates; and
 * Carbon's plain "sr" is Serbian in Latin script, while the installed Serbian
 * is Cyrillic. A machine translator is told the script or variant a language
 * is written in — Serbian in Cyrillic, Brazilian Portuguese, Simplified
 * Chinese — so its suggestions match the rest of the site. And a browser's
 * language tag reaches an installed language only when it does not name
 * another script or variant of it: Traditional Chinese is not the installed
 * Simplified one.
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

    /** The tag a translation into an installed language is asked for under, by code. */
    public const TRANSLATION_LOCALES = [
        'sr' => 'sr-Cyrl',
        'cnr' => 'cnr-Latn',
        'pt' => 'pt-BR',
        'zh' => 'zh-Hans',
    ];

    public static function translationLocale(string $locale): string
    {
        return self::TRANSLATION_LOCALES[$locale] ?? $locale;
    }

    /**
     * A primary language a browser may send for an installed one, by the tag's
     * primary language: Norwegian (no) is Bokmål, the only Norwegian
     * installed. Nynorsk (nn) is not, and reaches nothing.
     */
    public const BROWSER_ALIASES = [
        'no' => 'nb',
    ];

    /**
     * Installed languages that are one script or variant of several, by code:
     * the script a browser's tag may name, and the regions it may name when it
     * names no script, and still reach the installed language. A script
     * named decides; a region implies one only for these. Any other tag of the
     * language — zh-TW, sr-Latn, cnr-Cyrl, pt-PT — reaches nothing, and the
     * browser's next language is tried.
     *
     * @var array<string, array{script?: string, regions?: list<string>}>
     */
    public const BROWSER_VARIANTS = [
        'zh' => ['script' => 'hans', 'regions' => ['cn', 'sg', 'my']],
        'sr' => ['script' => 'cyrl'],
        'cnr' => ['script' => 'latn'],
        'pt' => ['regions' => ['br']],
    ];

    /**
     * The installed codes a browser's language tag may reach, in the order to
     * try them: the tag as written, then its primary language — `ru-RU`
     * reaches `ru` — unless the tag names a script or region another variant
     * of that language is written in. Lower-cased; `*` reaches nothing.
     *
     * @return list<string>
     */
    public static function browserCandidates(string $tag): array
    {
        $tag = strtolower(str_replace('_', '-', trim($tag)));

        if ($tag === '' || $tag === '*') {
            return [];
        }

        $subtags = explode('-', $tag);
        $primary = self::BROWSER_ALIASES[$subtags[0]] ?? $subtags[0];
        $candidates = [$tag];

        if (self::compatible($primary, array_slice($subtags, 1))) {
            $candidates[] = $primary;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Whether a tag's subtags — script, region, anything else — leave it a tag
     * of the installed variant of this language.
     *
     * @param  list<string>  $subtags
     */
    private static function compatible(string $language, array $subtags): bool
    {
        $variant = self::BROWSER_VARIANTS[$language] ?? null;

        if ($variant === null) {
            return true;
        }

        $script = null;
        $region = null;

        foreach ($subtags as $subtag) {
            if ($script === null && $region === null && preg_match('/^[a-z]{4}$/', $subtag) === 1) {
                $script = $subtag;
            } elseif ($region === null && preg_match('/^([a-z]{2}|[0-9]{3})$/', $subtag) === 1) {
                $region = $subtag;
            }
        }

        if (isset($variant['script']) && $script !== null) {
            return $script === $variant['script'];
        }

        if (isset($variant['regions']) && $region !== null) {
            return in_array($region, $variant['regions'], true);
        }

        return true;
    }

    public static function pluralLocale(string $locale): string
    {
        return self::PLURAL_RULES[$locale] ?? $locale;
    }

    public static function dateLocale(string $locale): string
    {
        return self::DATE_LOCALES[$locale] ?? $locale;
    }
}
