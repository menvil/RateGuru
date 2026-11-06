<?php

namespace App\Support\Translations\Generation;

use App\Support\Translations\ProjectTranslationUnit;

/**
 * Which English text a suggestion was made from, as a SHA-256 over the unit id
 * and its English reference.
 *
 * Taken when a batch is planned, compared again before a queued job sends an
 * item, when the browser restores a suggestion into a row, and before a
 * suggestion is saved. A suggestion whose English has changed since is never
 * sent, shown or saved: it translates text that is no longer there. This is
 * the one definition of it; nothing else hashes a source.
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
}
