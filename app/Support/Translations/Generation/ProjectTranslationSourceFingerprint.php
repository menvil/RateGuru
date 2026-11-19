<?php

namespace App\Support\Translations\Generation;

use App\Support\Translations\ProjectTranslationUnit;

/**
 * Which English text a suggestion or a translation was made from, as a SHA-256
 * over the unit id and its English reference.
 *
 * Taken when a batch is planned, compared again before a queued job sends an
 * item, when the browser restores a suggestion into a row, and before a
 * suggestion is saved. A suggestion whose English has changed since is never
 * sent, shown or saved: it translates text that is no longer there.
 *
 * Recorded with every saved translation as well (project_translation_sources),
 * so a translation whose English has changed since it was saved is told
 * apart as outdated (isOutdated()). This is the one definition of it; nothing
 * else hashes a source.
 */
final class ProjectTranslationSourceFingerprint
{
    public static function of(ProjectTranslationUnit $unit): string
    {
        return self::for($unit->id, $unit->reference);
    }

    public static function for(string $unitId, string $reference): string
    {
        return hash('sha256', json_encode(
            ['unit' => $unitId, 'reference' => $reference],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Whether a language's translation of the unit was made from English the
     * unit no longer has: the translation is stored, the fingerprint recorded
     * when it was saved is known, and it is not the unit's fingerprint now. A
     * translation with nothing recorded is not known to be outdated, and is
     * not; a missing one is missing, not outdated.
     */
    public static function isOutdated(ProjectTranslationUnit $unit, string $locale, ?string $recorded): bool
    {
        return $recorded !== null
            && $unit->translation($locale) !== null
            && $recorded !== self::of($unit);
    }
}
