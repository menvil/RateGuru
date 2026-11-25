<?php

namespace App\Queries\Translations;

use App\Support\Translations\Generation\ProjectTranslationSourceFingerprint;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationUnit;

/**
 * How many stored translations need an update — made from English their text
 * no longer has (ProjectTranslationSourceFingerprint::isOutdated()) — in each
 * language: what Languages and the content screens show beside what is
 * missing, as Translation Center's Needs update lists them. Reads only.
 */
final class OutdatedProjectTranslationsQuery
{
    public function __construct(
        private readonly ProjectTranslationCatalog $catalog,
        private readonly ProjectTranslationSourcesQuery $sources,
    ) {}

    /**
     * Over units already read, from one read of what is recorded.
     *
     * @param  list<ProjectTranslationUnit>  $units
     * @param  list<string>  $locales
     * @return array<string, int> by language, every one given
     */
    public function counts(array $units, array $locales, bool $named = false): array
    {
        $required = array_values(array_filter($units, fn (ProjectTranslationUnit $unit): bool => $unit->requiresTranslation()));
        $counts = array_fill_keys($locales, 0);

        if ($required === [] || $locales === []) {
            return $counts;
        }

        // A whole catalog reads every record of the languages; a few units, theirs alone.
        $recorded = $this->sources->fingerprintsIn($locales, $named ? array_map(fn (ProjectTranslationUnit $unit): string => $unit->id, $required) : null);

        foreach ($locales as $locale) {
            foreach ($required as $unit) {
                if (ProjectTranslationSourceFingerprint::isOutdated($unit, $locale, $recorded[$locale][$unit->id] ?? null)) {
                    $counts[$locale]++;
                }
            }
        }

        return $counts;
    }

    /**
     * Over the units of these sections, read for it.
     *
     * @param  list<ProjectContentSection>  $sections
     * @param  list<string>  $locales
     * @return array<string, int> by language, every one given
     */
    public function countsIn(array $sections, array $locales): array
    {
        return $this->counts(array_merge(...array_map(fn (ProjectContentSection $section): array => $this->catalog->unitsIn($section), $sections)), $locales, named: true);
    }
}
