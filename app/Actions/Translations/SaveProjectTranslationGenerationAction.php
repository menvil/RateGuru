<?php

namespace App\Actions\Translations;

use App\Actions\Translations\Concerns\ResolvesProjectTranslationGeneration;
use App\Exceptions\Translations\CannotGenerateTranslationsException;
use App\Exceptions\Translations\CannotSaveTranslationException;
use App\Models\User;
use App\Support\Translations\Generation\ProjectTranslationGenerationIssue;
use App\Support\Translations\Generation\ProjectTranslationGenerationItemStatus;
use App\Support\Translations\Generation\ProjectTranslationGenerationStore;
use App\Support\Translations\Generation\ProjectTranslationSourceFingerprint;
use App\Support\Translations\ProjectTranslationCatalog;
use Carbon\CarbonImmutable;

/**
 * Saves background AI suggestions — one, or every ready one: what Save on an
 * untouched generated draft and Save all generated do.
 *
 * The text saved is the suggestion as the store holds it, never anything the
 * browser sends. Each one is checked against the catalog as it is now first:
 * still listed, still the English it was generated from, still missing — a
 * suggestion for English that changed is skipped, and so is one for a
 * translation someone else saved meanwhile, which is never overwritten.
 * Then it is written by UpdateProjectTranslationAction, the one writer of
 * project translations, with its locks and limits.
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
     * @param  mixed  $unitId  one suggestion's unit, or null for every ready one
     * @param  mixed  $except  units to leave out of every ready one — rows the administrator has edited since
     * @return array{results: list<array{unit: string, outcome: string, value: string, message: ?string}>, saved: int, skipped: int, generation: ?array<string, mixed>}
     *
     * @throws CannotGenerateTranslationsException
     */
    public function handle(User $actor, mixed $locale, mixed $batchId, mixed $unitId = null, mixed $except = []): array
    {
        $this->authorize($actor);
        $locale = $this->targetLocale($locale);
        $batch = $this->ownedBatch($this->store, $actor, $locale, $batchId);
        $except = is_array($except) ? array_filter($except, 'is_string') : [];

        $ready = array_values(array_filter(
            ProjectTranslationGenerationStore::items($batch),
            fn (array $item): bool => $item['status'] === ProjectTranslationGenerationItemStatus::Ready->value
                && ($unitId === null ? ! in_array($item['id'], $except, true) : $item['id'] === $unitId),
        ));

        if ($unitId !== null && $ready === []) {
            throw CannotGenerateTranslationsException::becauseTheSuggestionIsNotReady();
        }

        $units = $this->catalog->findMany(array_map(fn (array $item): string => $item['id'], $ready));
        $outcomes = [];
        $results = [];

        foreach ($ready as $item) {
            $unit = $units[$item['id']] ?? null;
            $issue = match (true) {
                $unit === null => ProjectTranslationGenerationIssue::UnitUnavailable,
                ! $unit->requiresTranslation() => ProjectTranslationGenerationIssue::NothingToTranslate,
                ProjectTranslationSourceFingerprint::of($unit) !== $item['fingerprint'] => ProjectTranslationGenerationIssue::SourceChanged,
                $unit->translation($locale) !== null => ProjectTranslationGenerationIssue::AlreadyTranslated,
                default => null,
            };

            if ($issue !== null) {
                $outcomes[$item['id']] = ['status' => ProjectTranslationGenerationItemStatus::Skipped, 'issue' => $issue->value, 'message' => $issue->message()];
                $results[] = ['unit' => $item['id'], 'outcome' => $issue->value, 'value' => $unit?->translation($locale) ?? '', 'message' => $issue->message()];

                continue;
            }

            try {
                $saved = $this->translations->handle($actor, $item['id'], $locale, (string) $item['text']);
            } catch (CannotSaveTranslationException $exception) {
                $outcomes[$item['id']] = ['status' => ProjectTranslationGenerationItemStatus::Skipped, 'issue' => ProjectTranslationGenerationIssue::SaveRefused->value, 'message' => $exception->getMessage()];
                $results[] = ['unit' => $item['id'], 'outcome' => ProjectTranslationGenerationIssue::SaveRefused->value, 'value' => '', 'message' => $exception->getMessage()];

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
}
