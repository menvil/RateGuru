<?php

namespace App\Support\Languages;

/**
 * The site's language codes (config/locales.php) and the ones detectors use for some of them.
 */
final class LanguageCodes
{
    /** A detector's code → the site's. */
    private const ALIASES = ['no' => 'nb', 'nn' => 'nb', 'tl' => 'fil', 'iw' => 'he', 'in' => 'id', 'sh' => 'sr'];

    /**
     * The site's code for a detector's, or null when the site does not install that language.
     *
     * @param  list<string>  $installed
     */
    public static function toSite(string $code, array $installed): ?string
    {
        $code = strtolower(str_replace('_', '-', trim($code)));
        $code = self::ALIASES[$code] ?? (in_array($code, $installed, true) ? $code : explode('-', $code)[0]);
        $code = self::ALIASES[$code] ?? $code;

        return in_array($code, $installed, true) ? $code : null;
    }

    /**
     * The codes a detector knows the site's languages by.
     *
     * @param  list<string>  $installed
     * @return list<string>
     */
    public static function forDetector(array $installed): array
    {
        $back = array_flip(['no' => 'nb', 'tl' => 'fil']);

        return array_map(fn (string $code): string => $back[$code] ?? $code, $installed);
    }
}
