<?php

namespace App\Actions\Translations;

use App\Models\ProjectTranslationSource;
use App\Support\Translations\Generation\ProjectTranslationSourceFingerprint;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationUnit;

/**
 * Remembers which English one language's translation of one unit was made
 * from: the unit's source fingerprint and its English text, as the unit was
 * just written. A language left without a translation has nothing to
 * remember, and its record goes.
 *
 * Called by UpdateProjectTranslationAction with the unit it has just written,
 * inside its transaction and under the lock on the unit's row, so the record
 * always describes the translation stored beside it. The upsert is one
 * statement: a baseline adding a record for the same translation meanwhile
 * cannot make it fail.
 */
final class RecordProjectTranslationSourceAction
{
    public function handle(ProjectTranslationUnit $unit, string $locale): void
    {
        if ($unit->translation($locale) === null) {
            ProjectTranslationSource::query()->where('unit_id', $unit->id)->where('locale', $locale)->delete();

            return;
        }

        $now = now();

        ProjectTranslationSource::query()->upsert(
            [[
                'unit_id' => $unit->id,
                'locale' => $locale,
                'fingerprint' => ProjectTranslationSourceFingerprint::of($unit),
                'reference' => $unit->reference,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['unit_id', 'locale'],
            ['fingerprint', 'reference', 'updated_at'],
        );
    }

    /**
     * Carries what one record's translations were made from over to its copy
     * — a game's content set or flow copied with its texts and translations —
     * so a translation outdated on the original is outdated on the copy too,
     * not taken for current by the next baseline. The copy's own unit ids, the
     * same English recorded under them; a record the copy already has stays.
     * Called inside the copying transaction.
     */
    public function copy(ProjectContentSection $section, int $fromId, int $toId): void
    {
        $fields = array_keys(ProjectTranslationCatalog::FIELDS[$section->value]);
        $records = ProjectTranslationSource::query()
            ->whereIn('unit_id', array_map(fn (string $field): string => "{$section->value}:{$fromId}:{$field}", $fields))
            ->get(['unit_id', 'locale', 'reference']);

        if ($records->isEmpty()) {
            return;
        }

        $now = now();

        ProjectTranslationSource::query()->insertOrIgnore($records->map(function (ProjectTranslationSource $record) use ($section, $toId, $now): array {
            $unitId = "{$section->value}:{$toId}:".substr((string) $record->unit_id, (int) strrpos((string) $record->unit_id, ':') + 1);

            return [
                'unit_id' => $unitId,
                'locale' => $record->locale,
                'fingerprint' => ProjectTranslationSourceFingerprint::for($unitId, (string) $record->reference),
                'reference' => $record->reference,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all());
    }
}
