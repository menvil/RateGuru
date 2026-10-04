<?php

namespace App\Support\Translations;

/**
 * One way a language's application catalogs break the translation contract.
 */
final readonly class TranslationCatalogIssue
{
    public const MISSING_CATALOG = 'missing_catalog';

    public const EXTRA_CATALOG = 'extra_catalog';

    public const ENGLISH_ONLY_CATALOG = 'english_only_catalog';

    public const JSON_CATALOG = 'json_catalog';

    public const MISSING_KEY = 'missing_key';

    public const EXTRA_KEY = 'extra_key';

    public const INVALID_LINE = 'invalid_line';

    public const PLACEHOLDER_MISMATCH = 'placeholder_mismatch';

    public const COPIED_CATALOG = 'copied_catalog';

    public function __construct(
        public string $type,
        public string $locale,
        public ?string $catalog,
        public ?string $key,
        public string $message,
    ) {}

    public static function missingCatalog(string $locale, string $catalog): self
    {
        return new self(self::MISSING_CATALOG, $locale, $catalog, null, "{$locale}/{$catalog}.php is missing");
    }

    public static function extraCatalog(string $locale, string $catalog): self
    {
        return new self(self::EXTRA_CATALOG, $locale, $catalog, null, "{$locale}/{$catalog}.php has no English reference, so nothing checks it");
    }

    public static function englishOnlyCatalog(string $locale, string $catalog): self
    {
        return new self(self::ENGLISH_ONLY_CATALOG, $locale, $catalog, null, "{$locale}/{$catalog}.php translates a catalog that is only ever read in English");
    }

    public static function jsonCatalog(string $locale): self
    {
        return new self(self::JSON_CATALOG, $locale, null, null, "{$locale}.json is a JSON catalog, keyed by English prose, which nothing here can check");
    }

    public static function missingKey(string $locale, string $catalog, string $key): self
    {
        return new self(self::MISSING_KEY, $locale, $catalog, $key, "{$locale}/{$catalog}.php is missing [{$key}]");
    }

    public static function extraKey(string $locale, string $catalog, string $key): self
    {
        return new self(self::EXTRA_KEY, $locale, $catalog, $key, "{$locale}/{$catalog}.php has [{$key}], which English does not");
    }

    public static function invalidLine(string $locale, string $catalog, string $key, mixed $value): self
    {
        $message = is_string($value)
            ? "{$locale}/{$catalog}.php has an empty [{$key}]"
            : "{$locale}/{$catalog}.php has a ".get_debug_type($value)." at [{$key}]";

        return new self(self::INVALID_LINE, $locale, $catalog, $key, $message);
    }

    /**
     * @param  list<string>  $declared
     * @param  list<string>  $expected
     */
    public static function placeholderMismatch(string $locale, string $catalog, string $key, array $declared, array $expected): self
    {
        return new self(self::PLACEHOLDER_MISMATCH, $locale, $catalog, $key, sprintf(
            '%s/%s.php [%s] declares (%s), English declares (%s)',
            $locale, $catalog, $key,
            implode(', ', $declared) ?: 'none',
            implode(', ', $expected) ?: 'none',
        ));
    }

    public static function copiedCatalog(string $locale, string $catalog): self
    {
        return new self(self::COPIED_CATALOG, $locale, $catalog, null, "{$locale}/{$catalog}.php is English, line for line");
    }
}
