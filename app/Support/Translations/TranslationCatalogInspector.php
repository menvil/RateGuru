<?php

namespace App\Support\Translations;

/**
 * Checks a language's application catalogs (lang/{locale}/*.php) against the
 * English reference — the one implementation of the translation contract.
 * TranslationParityTest enforces it in CI; the Languages page reads it on a
 * running server, so a release that somehow arrives broken fails closed there
 * too.
 *
 * The contract: the same catalog files as English, the same keys and nothing
 * extra, a non-blank string on every line, the same :placeholders, no catalog
 * that is English line for line, and no JSON catalogs. Catalogs only ever read
 * in English (the admin panel) are not part of what a language translates.
 *
 * An inspector reads the English reference once and keeps it: every language
 * is checked against the same files, so offering every installed language, or
 * drawing the Languages page, reads English once rather than once per
 * language. Make a new one to see catalogs that changed since.
 */
final class TranslationCatalogInspector
{
    /** The language every other catalog is checked against. */
    public const REFERENCE_LOCALE = TranslatableField::REFERENCE_LOCALE;

    /** Catalogs that exist only in English, because nothing reads them in another language. */
    public const ENGLISH_ONLY_CATALOGS = ['admin'];

    private readonly string $langPath;

    /** @var list<string>|null */
    private ?array $referenceCatalogs = null;

    /** @var array<string, array<string, mixed>> English lines by catalog. */
    private array $referenceLines = [];

    /** @var array<string, array<string, list<string>>> The :placeholders of each English line, by catalog and key. */
    private array $referencePlaceholders = [];

    public function __construct(?string $langPath = null)
    {
        $this->langPath = rtrim($langPath ?? lang_path(), '/');
    }

    /** @return list<string> */
    public function catalogsIn(string $locale): array
    {
        $catalogs = array_map(
            fn (string $path): string => basename($path, '.php'),
            glob("{$this->langPath}/{$locale}/*.php") ?: [],
        );
        sort($catalogs);

        return $catalogs;
    }

    /**
     * The catalogs every language has to translate.
     *
     * @return list<string>
     */
    public function referenceCatalogs(): array
    {
        return $this->referenceCatalogs ??= array_values(array_diff($this->catalogsIn(self::REFERENCE_LOCALE), self::ENGLISH_ONLY_CATALOGS));
    }

    /**
     * A catalog's lines, keyed by their dotted key; empty when the file does
     * not exist.
     *
     * @return array<string, mixed>
     */
    public function lines(string $locale, string $catalog): array
    {
        if ($locale === self::REFERENCE_LOCALE) {
            return $this->referenceLines[$catalog] ??= $this->read($locale, $catalog);
        }

        return $this->read($locale, $catalog);
    }

    /** @return list<string> */
    public function jsonCatalogs(): array
    {
        return array_map(fn (string $path): string => basename($path), glob("{$this->langPath}/*.json") ?: []);
    }

    public function inspect(string $locale): TranslationCatalogReport
    {
        $issues = is_file("{$this->langPath}/{$locale}.json") ? [TranslationCatalogIssue::jsonCatalog($locale)] : [];

        return $locale === self::REFERENCE_LOCALE
            ? $this->inspectReference($issues)
            : $this->inspectTranslation($locale, $issues);
    }

    /** @param  list<TranslationCatalogIssue>  $issues */
    private function inspectReference(array $issues): TranslationCatalogReport
    {
        $reference = $this->referenceCatalogs();
        $expected = 0;
        $complete = 0;

        // Every English line has to be text, the English-only catalogs included;
        // only the catalogs other languages translate count towards the total.
        foreach ($this->catalogsIn(self::REFERENCE_LOCALE) as $catalog) {
            $counted = in_array($catalog, $reference, true);

            foreach ($this->lines(self::REFERENCE_LOCALE, $catalog) as $key => $value) {
                $usable = $this->isText($value);

                if (! $usable) {
                    $issues[] = TranslationCatalogIssue::invalidLine(self::REFERENCE_LOCALE, $catalog, $key, $value);
                }

                $expected += $counted ? 1 : 0;
                $complete += $counted && $usable ? 1 : 0;
            }
        }

        return new TranslationCatalogReport(self::REFERENCE_LOCALE, $expected, $complete, $issues);
    }

    /** @param  list<TranslationCatalogIssue>  $issues */
    private function inspectTranslation(string $locale, array $issues): TranslationCatalogReport
    {
        $reference = $this->referenceCatalogs();
        $actual = $this->catalogsIn($locale);

        foreach (array_diff($reference, $actual) as $catalog) {
            $issues[] = TranslationCatalogIssue::missingCatalog($locale, $catalog);
        }

        foreach (array_intersect($actual, self::ENGLISH_ONLY_CATALOGS) as $catalog) {
            $issues[] = TranslationCatalogIssue::englishOnlyCatalog($locale, $catalog);
        }

        // A catalog English does not have is usually a rename applied to one
        // language only — and since every check below walks the English files,
        // it would never be opened at all.
        foreach (array_diff($actual, $reference, self::ENGLISH_ONLY_CATALOGS) as $catalog) {
            $issues[] = TranslationCatalogIssue::extraCatalog($locale, $catalog);
        }

        $expected = 0;
        $complete = 0;

        foreach ($reference as $catalog) {
            $referenceLines = $this->lines(self::REFERENCE_LOCALE, $catalog);
            $translated = $this->lines($locale, $catalog);
            $exists = in_array($catalog, $actual, true);

            // Same keys, same placeholders, nothing blank — and still English
            // to every reader, if the file was copied and never translated.
            $copied = $translated !== [] && $translated == $referenceLines;

            if ($copied) {
                $issues[] = TranslationCatalogIssue::copiedCatalog($locale, $catalog);
            }

            foreach ($referenceLines as $key => $referenceLine) {
                $expected++;

                if (! array_key_exists($key, $translated)) {
                    // A missing file is one issue, not one per line.
                    if ($exists) {
                        $issues[] = TranslationCatalogIssue::missingKey($locale, $catalog, $key);
                    }

                    continue;
                }

                $line = $translated[$key];

                if (! $this->isText($line)) {
                    $issues[] = TranslationCatalogIssue::invalidLine($locale, $catalog, $key, $line);

                    continue;
                }

                if (is_string($referenceLine) && $this->placeholdersIn($line) !== ($expectedPlaceholders = $this->referencePlaceholders[$catalog][$key] ??= $this->placeholdersIn($referenceLine))) {
                    $issues[] = TranslationCatalogIssue::placeholderMismatch(
                        $locale, $catalog, $key, $this->placeholdersIn($line), $expectedPlaceholders,
                    );

                    continue;
                }

                $complete += $copied ? 0 : 1;
            }

            foreach (array_keys(array_diff_key($translated, $referenceLines)) as $key) {
                $issues[] = TranslationCatalogIssue::extraKey($locale, $catalog, $key);
            }
        }

        return new TranslationCatalogReport($locale, $expected, $complete, $issues);
    }

    /** @return array<string, mixed> */
    private function read(string $locale, string $catalog): array
    {
        $path = "{$this->langPath}/{$locale}/{$catalog}.php";

        return is_file($path) ? $this->flatten((array) require $path) : [];
    }

    /** A line a reader can be shown: a string with something in it. */
    private function isText(mixed $value): bool
    {
        return TranslatableField::isPresent($value);
    }

    /**
     * The :placeholders a line declares, which must survive translation.
     *
     * @return list<string>
     */
    private function placeholdersIn(string $line): array
    {
        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $matches);

        $placeholders = array_values(array_unique($matches[1]));
        sort($placeholders);

        return $placeholders;
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array<string, mixed>
     */
    private function flatten(array $lines, string $prefix = ''): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}
