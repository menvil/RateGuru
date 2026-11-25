<?php

namespace App\Queries\Games;

use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Queries\Translations\ProjectTranslationSourcesQuery;
use App\Support\Locale\LocaleManager;
use App\Support\Translations\Generation\ProjectTranslationSourceFingerprint;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationUnit;
use App\Support\Translations\TranslatableField;

/**
 * How far the texts a version would freeze are translated, before it is
 * published: in each language the project offers besides English, how many of
 * the texts a version from this content set and flow would freeze have no
 * translation — players of that language read their English — and how many
 * have one made from English the text no longer has, which players would read
 * with its old wording (ProjectTranslationSourceFingerprint::isOutdated()).
 * The texts are the ones Translation Center lists (ProjectTranslationCatalog),
 * so every gap counted here is one an administrator can fill there. Versions
 * warns with it; publishing is never held back by it. Reads only.
 */
final class VersionTranslationCoverageQuery
{
    public function __construct(
        private readonly ProjectTranslationCatalog $catalog,
        private readonly ProjectTranslationSourcesQuery $sources,
        private readonly LocaleManager $locales,
    ) {}

    /**
     * The languages with a text missing or needing an update, in the order the
     * project offers them; none when every text is translated and current.
     *
     * @return list<array{locale: string, language: string, missing: int, outdated: int}>
     */
    public function gaps(Game $game, GameContentSet $set, GameFlow $flow): array
    {
        $units = array_values(array_filter(
            $this->catalog->unitsOfVersion($game, $set, $flow),
            fn (ProjectTranslationUnit $unit): bool => $unit->requiresTranslation(),
        ));
        $locales = array_values(array_diff($this->locales->enabledCodes(), [TranslatableField::REFERENCE_LOCALE]));

        if ($units === [] || $locales === []) {
            return [];
        }

        $recorded = $this->sources->fingerprintsIn($locales, array_map(fn (ProjectTranslationUnit $unit): string => $unit->id, $units));
        $gaps = [];

        foreach ($locales as $locale) {
            $missing = 0;
            $outdated = 0;

            foreach ($units as $unit) {
                if ($unit->translation($locale) === null) {
                    $missing++;
                } elseif (ProjectTranslationSourceFingerprint::isOutdated($unit, $locale, $recorded[$locale][$unit->id] ?? null)) {
                    $outdated++;
                }
            }

            if ($missing + $outdated > 0) {
                $gaps[] = ['locale' => $locale, 'language' => $this->locales->label($locale), 'missing' => $missing, 'outdated' => $outdated];
            }
        }

        return $gaps;
    }
}
