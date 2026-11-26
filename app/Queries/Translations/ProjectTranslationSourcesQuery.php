<?php

namespace App\Queries\Translations;

use App\Models\ProjectTranslationSource;

/**
 * Which English the stored translations of one language were made from, as
 * project_translation_sources records it — what tells an outdated
 * translation (ProjectTranslationSourceFingerprint::isOutdated()).
 */
final class ProjectTranslationSourcesQuery
{
    /**
     * Every record of the language, by unit id, in one query: what
     * Translation Center shows a whole language with.
     *
     * @return array<string, ProjectTranslationSource>
     */
    public function forLocale(string $locale): array
    {
        return ProjectTranslationSource::query()
            ->where('locale', $locale)
            ->get(['unit_id', 'locale', 'fingerprint', 'reference'])
            ->keyBy('unit_id')
            ->all();
    }

    /**
     * The recorded fingerprints of the language, by unit id, in one query —
     * of these units only, when they are named. A unit with nothing recorded
     * is absent.
     *
     * @param  list<string>|null  $unitIds
     * @return array<string, string>
     */
    public function fingerprints(string $locale, ?array $unitIds = null): array
    {
        if ($unitIds === []) {
            return [];
        }

        return ProjectTranslationSource::query()
            ->where('locale', $locale)
            ->when($unitIds !== null, fn ($query) => $query->whereIn('unit_id', array_values(array_unique($unitIds ?? []))))
            ->pluck('fingerprint', 'unit_id')
            ->map(fn (mixed $fingerprint): string => (string) $fingerprint)
            ->all();
    }

    /**
     * The recorded fingerprints in each of these languages — of these units
     * only, when they are named — by language and then unit id, in one query.
     * A unit with nothing recorded in a language is absent from it.
     *
     * @param  list<string>  $locales
     * @param  list<string>|null  $unitIds
     * @return array<string, array<string, string>>
     */
    public function fingerprintsIn(array $locales, ?array $unitIds = null): array
    {
        if ($locales === [] || $unitIds === []) {
            return [];
        }

        $fingerprints = [];

        foreach (ProjectTranslationSource::query()
            ->whereIn('locale', array_values(array_unique($locales)))
            ->when($unitIds !== null, fn ($query) => $query->whereIn('unit_id', array_values(array_unique($unitIds ?? []))))
            ->toBase()
            ->get(['unit_id', 'locale', 'fingerprint']) as $source) {
            $fingerprints[(string) $source->locale][(string) $source->unit_id] = (string) $source->fingerprint;
        }

        return $fingerprints;
    }
}
