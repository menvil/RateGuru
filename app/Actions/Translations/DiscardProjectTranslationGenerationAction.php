<?php

namespace App\Actions\Translations;

use App\Actions\Translations\Concerns\ResolvesProjectTranslationGeneration;
use App\Exceptions\Translations\CannotGenerateTranslationsException;
use App\Models\User;
use App\Support\Translations\Generation\ProjectTranslationGenerationItemStatus;
use App\Support\Translations\Generation\ProjectTranslationGenerationStatus;
use App\Support\Translations\Generation\ProjectTranslationGenerationStore;
use Carbon\CarbonImmutable;

/**
 * Discards background AI suggestions — one, or every ready one — in the
 * store, so a reload does not bring them back. Project translations are never
 * touched: a suggestion is not one, and one already saved stays saved.
 *
 * Discarding every ready one of a finished batch also lets it go: the
 * language then offers Generate missing again.
 */
final class DiscardProjectTranslationGenerationAction
{
    use ResolvesProjectTranslationGeneration;

    public function __construct(private readonly ProjectTranslationGenerationStore $store) {}

    /**
     * @param  mixed  $unitId  one suggestion's unit, or null for every ready one
     * @return array<string, mixed>|null the batch's summary for the browser, or null once it has been let go
     *
     * @throws CannotGenerateTranslationsException
     */
    public function handle(User $actor, mixed $locale, mixed $batchId, mixed $unitId = null): ?array
    {
        $this->authorize($actor);
        $locale = $this->targetLocale($locale);
        $batch = $this->ownedBatch($this->store, $actor, $locale, $batchId);

        $outcomes = [];

        foreach (ProjectTranslationGenerationStore::items($batch) as $item) {
            if ($unitId === null || $item['id'] === $unitId) {
                $outcomes[$item['id']] = ['status' => ProjectTranslationGenerationItemStatus::Discarded];
            }
        }

        $batch = $this->store->resolve($batch['meta']['id'], $outcomes, CarbonImmutable::now());

        if ($batch === null) {
            return null;
        }

        if ($unitId === null && $batch['meta']['status'] === ProjectTranslationGenerationStatus::Completed->value) {
            $this->store->forgetActive((int) $actor->getKey(), $locale, $batch['meta']['id']);

            return null;
        }

        return ProjectTranslationGenerationStore::summary($batch);
    }

    /**
     * Marks the actor's background suggestion for one unit as superseded once
     * a translation of the unit has been saved some other way — the
     * suggestion edited and saved as a draft, an interactive suggestion, a
     * manual translation — so a reload never offers it again. Best effort: a
     * store that cannot be reached changes nothing.
     */
    public function supersede(User $actor, string $locale, string $unitId): void
    {
        try {
            $batchId = $this->store->activeBatchId((int) $actor->getKey(), $locale);

            if ($batchId !== null) {
                $this->store->resolve($batchId, [$unitId => ['status' => ProjectTranslationGenerationItemStatus::Discarded]], CarbonImmutable::now());
            }
        } catch (CannotGenerateTranslationsException) {
            // Nothing to supersede that a reload could restore while the store is down either.
        }
    }
}
