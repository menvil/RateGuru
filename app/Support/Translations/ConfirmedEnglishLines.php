<?php

namespace App\Support\Translations;

use JsonException;

/**
 * The English of the interface catalogs (lang/en/*.php) as every language's
 * translation of it was last confirmed: a short hash of each line, by
 * `catalog.key`, kept in the repository (RECORD). An English line reworded
 * under the same key after its translations were made leaves every other
 * language saying the old thing while the parity checks pass; a line whose
 * English no longer hashes as recorded is told apart (changed()), so its
 * translations are updated before the change ships. Recording the English as
 * it is now (record()) is what confirms that they were.
 *
 * Only reworded lines are flagged: a new key is held to every language by the
 * parity checks already, and is recorded with the next confirmation. The
 * English-only catalogs have no translations to keep in step.
 */
final class ConfirmedEnglishLines
{
    /** Where the record is kept, from the repository's root. */
    public const string RECORD = 'resources/translations/confirmed-english.json';

    /** How many hex digits of a line's SHA-256 are kept: enough to tell a reworded line, short enough to review. */
    private const int HASH_LENGTH = 16;

    private readonly TranslationCatalogInspector $catalogs;

    private readonly string $record;

    /** The repository's own catalogs and record unless others are given, as a test gives its scratch copies. */
    public function __construct(?TranslationCatalogInspector $catalogs = null, ?string $record = null)
    {
        $this->catalogs = $catalogs ?? new TranslationCatalogInspector;
        $this->record = $record ?? base_path(self::RECORD);
    }

    /**
     * Each English line's hash as it reads now, by `catalog.key`, in order.
     *
     * @return array<string, string>
     */
    public function current(): array
    {
        $hashes = [];

        foreach ($this->catalogs->referenceCatalogs() as $catalog) {
            foreach ($this->catalogs->lines(TranslationCatalogInspector::REFERENCE_LOCALE, $catalog) as $key => $line) {
                if (is_string($line)) {
                    $hashes["{$catalog}.{$key}"] = substr(hash('sha256', $line), 0, self::HASH_LENGTH);
                }
            }
        }

        ksort($hashes);

        return $hashes;
    }

    /**
     * The hashes recorded when the translations were last confirmed; none
     * before the first confirmation.
     *
     * @return array<string, string>
     */
    public function recorded(): array
    {
        if (! is_file($this->record)) {
            return [];
        }

        try {
            $recorded = json_decode((string) file_get_contents($this->record), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($recorded) ? array_filter($recorded, fn (mixed $hash, mixed $key): bool => is_string($key) && is_string($hash), ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * The lines whose English changed since their translations were
     * confirmed: recorded, still there, and reading otherwise now.
     *
     * @return list<string>
     */
    public function changed(): array
    {
        $current = $this->current();
        $changed = [];

        foreach ($this->recorded() as $key => $hash) {
            if (isset($current[$key]) && $current[$key] !== $hash) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * Records the English as it reads now, confirming every language's
     * translation of it: changed lines take their new hash, new lines are
     * added, lines that are gone are dropped.
     *
     * @return array{changed: int, added: int, removed: int}
     */
    public function record(): array
    {
        $current = $this->current();
        $recorded = $this->recorded();
        $counts = [
            'changed' => count($this->changed()),
            'added' => count(array_diff_key($current, $recorded)),
            'removed' => count(array_diff_key($recorded, $current)),
        ];

        if (! is_dir(dirname($this->record))) {
            mkdir(dirname($this->record), 0755, true);
        }

        file_put_contents($this->record, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return $counts;
    }
}
