<?php

namespace App\Actions\Translations;

use App\Models\ProjectTranslationSource;
use App\Queries\Translations\ProjectTranslationSourcesQuery;
use App\Support\Locale\LocaleManager;
use App\Support\Translations\Generation\ProjectTranslationSourceFingerprint;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\TranslatableField;

/**
 * Gives every stored translation that has no record of the English it was
 * made from one: the unit's English as it is now. Translation Center records
 * it with each save; this covers the translations saved before it did, and
 * those written some other way — a project preset, the backfill a deploy
 * runs.
 *
 * A record is only ever added, never changed: one that exists says which
 * English its translation was made from, and replacing it with today's would
 * hide that the English has changed since. A record is added for a unit the
 * catalog lists, in every installed language but English, where the unit has
 * English to translate and the language stores a translation of it.
 *
 * Added one language at a time, in short inserts that skip a record a save
 * added meanwhile, so it is safe beside Translation Center and safe to run
 * again: a second run finds nothing to add.
 */
final class BaselineProjectTranslationSourcesAction
{
    private const INSERT_SIZE = 500;

    public function __construct(
        private readonly ProjectTranslationCatalog $catalog,
        private readonly ProjectTranslationSourcesQuery $sources,
        private readonly LocaleManager $locales,
    ) {}

    /** @return int how many records it added */
    public function handle(): int
    {
        $units = array_values(array_filter($this->catalog->units(), fn ($unit): bool => $unit->requiresTranslation()));
        $added = 0;

        foreach (array_keys($this->locales->supported()) as $locale) {
            if ($locale === TranslatableField::REFERENCE_LOCALE) {
                continue;
            }

            $recorded = $this->sources->fingerprints($locale);
            $now = now();
            $rows = [];

            foreach ($units as $unit) {
                if ($unit->translation($locale) !== null && ! array_key_exists($unit->id, $recorded)) {
                    $rows[] = [
                        'unit_id' => $unit->id,
                        'locale' => $locale,
                        'fingerprint' => ProjectTranslationSourceFingerprint::of($unit),
                        'reference' => $unit->reference,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, self::INSERT_SIZE) as $chunk) {
                $added += ProjectTranslationSource::query()->insertOrIgnore($chunk);
            }
        }

        return $added;
    }
}
