<?php

namespace App\Actions\Translations;

use App\Actions\Translations\Concerns\ResolvesProjectTranslationGeneration;
use App\Exceptions\Translations\CannotGenerateTranslationsException;
use App\Exceptions\Translations\CannotSaveTranslationException;
use App\Exceptions\Translations\GeneratedSuggestionOutdatedException;
use App\Models\User;
use App\Support\Translations\Generation\ProjectTranslationGenerationIssue;
use App\Support\Translations\Generation\ProjectTranslationGenerationItemStatus;
use App\Support\Translations\Generation\ProjectTranslationGenerationStore;
use App\Support\Translations\Generation\ProjectTranslationSourceFingerprint;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationUnit;
use Carbon\CarbonImmutable;

/**
 * Saves background AI suggestions — one, or the ones the page names: what Save
 * on an untouched generated draft and Save all generated do. Save all names
 * the rows it shows untouched, and nothing else is saved — not a suggestion
 * that became ready after the page last looked, which nobody has seen yet.
 *
 * The text saved is the suggestion as the store holds it, never anything the
 * browser sends. A suggestion is saved only while its unit is still listed,
 * still has the English it was generated from (by source fingerprint) and is
 * still missing: one for English that changed is skipped, and so is one for
 * a translation someone else saved meanwhile, which is never overwritten.
 *
 * That is decided on the unit's locked row, by the guard of
 * UpdateProjectTranslationAction::handleGuarded() — the one writer of project
 * translations, with its locks and limits — so nothing saved or changed in
 * between can slip past it. The same check on one early read of every unit
 * only spares writes that are bound to be refused; it guarantees nothing.
 *
 * Each suggestion stands alone: there is no transaction around them, so the
 * ones saved stay saved whatever happens to the others.
 */
final class SaveProjectTranslationGenerationAction
{
    use ResolvesProjectTranslationGeneration;

    public function __construct(
        private readonly ProjectTranslationGenerationStore $store,
        private readonly ProjectTranslationCatalog $catalog,
        private readonly UpdateProjectTranslationAction $translations,
    ) {}

    /**
     * @param  mixed  $unitId  one suggestion's unit, or null for the ones $units names
     * @param  mixed  $units  Save all generated: the units whose suggestions the page shows untouched — only those,
     *                        and only while ready; anything but a list of them saves nothing
     * @return array{results: list<array{unit: string, outcome: string, value: string, message: ?string}>, saved: int, skipped: int, generation: ?array<string, mixed>}
     *
     * @throws CannotGenerateTranslationsException
     */
    public function handle(User $actor, mixed $locale, mixed $batchId, mixed $unitId = null, mixed $units = []): array
    {
        $this->authorize($actor);
        $locale = $this->targetLocale($locale);
        $batch = $this->ownedBatch($this->store, $actor, $locale, $batchId);
        $units = is_array($units) ? array_values(array_filter($units, 'is_string')) : [];

        $ready = array_values(array_filter(
            ProjectTranslationGenerationStore::items($batch),
            fn (array $item): bool => $item['status'] === ProjectTranslationGenerationItemStatus::Ready->value
                && ($unitId === null ? in_array($item['id'], $units, true) : $item['id'] === $unitId),
        ));

        if ($unitId !== null && $ready === []) {
            throw CannotGenerateTranslationsException::becauseTheSuggestionIsNotReady();
        }

        // The early read: what is bound to be refused is not even tried.
        $units = $this->catalog->findMany(array_map(fn (array $item): string => $item['id'], $ready));
        $outcomes = [];
        $results = [];

        $skip = function (string $unit, ProjectTranslationGenerationIssue $issue, ?string $stored, ?string $message = null) use (&$outcomes, &$results): void {
            $message ??= $issue->message();
            $outcomes[$unit] = ['status' => ProjectTranslationGenerationItemStatus::Skipped, 'issue' => $issue->value, 'message' => $message];
            $results[] = ['unit' => $unit, 'outcome' => $issue->value, 'value' => $stored ?? '', 'message' => $message];
        };

        foreach ($ready as $item) {
            $unit = $units[$item['id']] ?? null;
            $issue = self::issueOf($unit, $item['fingerprint'], $locale);

            if ($issue !== null) {
                $skip($item['id'], $issue, $unit?->translation($locale));

                continue;
            }

            try {
                $saved = $this->translations->handleGuarded($actor, $item['id'], $locale, (string) $item['text'], function (ProjectTranslationUnit $locked) use ($item, $locale): void {
                    $issue = self::issueOf($locked, $item['fingerprint'], $locale);

                    if ($issue !== null) {
                        throw new GeneratedSuggestionOutdatedException($issue, $locked->translation($locale));
                    }
                });
            } catch (GeneratedSuggestionOutdatedException $exception) {
                $skip($item['id'], $exception->issue, $exception->stored);

                continue;
            } catch (CannotSaveTranslationException $exception) {
                if ($exception->reason === CannotSaveTranslationException::REASON_UNKNOWN_UNIT) {
                    $skip($item['id'], ProjectTranslationGenerationIssue::UnitUnavailable, null);
                } else {
                    $skip($item['id'], ProjectTranslationGenerationIssue::SaveRefused, null, $exception->getMessage());
                }

                continue;
            }

            $outcomes[$item['id']] = ['status' => ProjectTranslationGenerationItemStatus::Saved];
            $results[] = ['unit' => $item['id'], 'outcome' => 'saved', 'value' => $saved->translation($locale) ?? '', 'message' => null];
        }

        $batch = $this->store->resolve($batch['meta']['id'], $outcomes, CarbonImmutable::now());
        $saved = count(array_filter($results, fn (array $result): bool => $result['outcome'] === 'saved'));

        return [
            'results' => $results,
            'saved' => $saved,
            'skipped' => count($results) - $saved,
            'generation' => $batch !== null ? ProjectTranslationGenerationStore::summary($batch) : null,
        ];
    }

    /**
     * Why a suggestion made from the English with this fingerprint may not be
     * saved into the unit as it is, or null when it may: the unit is gone,
     * has no English, has other English now, or has a translation already.
     */
    private static function issueOf(?ProjectTranslationUnit $unit, string $fingerprint, string $locale): ?ProjectTranslationGenerationIssue
    {
        return match (true) {
            $unit === null => ProjectTranslationGenerationIssue::UnitUnavailable,
            ! $unit->requiresTranslation() => ProjectTranslationGenerationIssue::NothingToTranslate,
            ProjectTranslationSourceFingerprint::of($unit) !== $fingerprint => ProjectTranslationGenerationIssue::SourceChanged,
            $unit->translation($locale) !== null => ProjectTranslationGenerationIssue::AlreadyTranslated,
            default => null,
        };
    }
}
