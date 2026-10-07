<?php

namespace App\Actions\Translations;

use App\Actions\Translations\Concerns\ResolvesProjectTranslationGeneration;
use App\Exceptions\Translations\CannotGenerateTranslationsException;
use App\Jobs\Translations\GenerateProjectTranslationChunkJob;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use App\Support\TranslationEngine\Exceptions\InvalidTranslationRequestException;
use App\Support\TranslationEngine\Exceptions\TranslationConfigurationException;
use App\Support\Translations\Generation\ProjectTranslationGenerationChunkStatus;
use App\Support\Translations\Generation\ProjectTranslationGenerationIssue;
use App\Support\Translations\Generation\ProjectTranslationGenerationPlanner;
use App\Support\Translations\Generation\ProjectTranslationGenerationStatus;
use App\Support\Translations\Generation\ProjectTranslationGenerationStore;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationRequestFactory;
use App\Support\Translations\ProjectTranslationUnit;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Generate missing: AI suggestions for every translation a language is
 * missing, made in the background.
 *
 * Every one, whatever the page's filters show — the units are the catalog's
 * own, read now; the browser names only the language. They are described to
 * the engine through ProjectTranslationRequestFactory, exactly as an
 * interactive suggestion is, planned into chunks of one provider request each
 * (ProjectTranslationGenerationPlanner), stored as a batch owned by the actor
 * (ProjectTranslationGenerationStore), and one job is queued per chunk. This
 * request plans, stores and queues; it never waits for a provider.
 *
 * It spends nothing it does not have to:
 *
 *  - the actor's batch for the language, while it runs or has suggestions
 *    waiting for review, is returned rather than generated again;
 *  - a language another administrator is generating is refused, until that
 *    generation finishes;
 *  - nothing missing, or no provider configured, queues nothing.
 */
final class StartProjectTranslationGenerationAction
{
    use ResolvesProjectTranslationGeneration;

    public function __construct(
        private readonly ProjectTranslationCatalog $catalog,
        private readonly ProjectTranslationRequestFactory $requests,
        private readonly ProjectTranslationGenerationPlanner $planner,
        private readonly ProjectTranslationGenerationStore $store,
    ) {}

    /**
     * @return array<string, mixed> the batch's summary for the browser
     *
     * @throws CannotGenerateTranslationsException
     */
    public function handle(User $actor, mixed $locale): array
    {
        $this->authorize($actor);
        $locale = $this->targetLocale($locale);
        $userId = (int) $actor->getKey();

        $current = $this->current($userId, $locale);

        if ($current !== null) {
            return $current;
        }

        $missing = array_values(array_filter(
            $this->catalog->units(),
            fn (ProjectTranslationUnit $unit): bool => $unit->requiresTranslation() && $unit->translation($locale) === null,
        ));

        if ($missing === []) {
            throw CannotGenerateTranslationsException::becauseNothingIsMissing();
        }

        try {
            $plan = $this->planner->plan($this->requests->make($missing, $locale));
        } catch (TranslationConfigurationException $exception) {
            throw CannotGenerateTranslationsException::becauseTheEngineFailed($exception->errorCode);
        } catch (InvalidTranslationRequestException) {
            throw CannotGenerateTranslationsException::becauseItCouldNotBeQueued();
        }

        [$batch, $created] = $this->store->lockLanguage($locale, function () use ($userId, $locale, $plan): array {
            $runningId = $this->store->runningBatchId($locale);

            if ($runningId !== null) {
                $running = $this->store->read($runningId, CarbonImmutable::now());

                if ($running !== null && $running['meta']['status'] !== ProjectTranslationGenerationStatus::Completed->value) {
                    if ($running['meta']['user_id'] !== $userId) {
                        throw CannotGenerateTranslationsException::becauseAnotherAdministratorIsGenerating(app(LocaleManager::class)->label($locale));
                    }

                    // The actor's own, already queued: nothing to queue again.
                    return [$running, false];
                }

                $this->store->forgetRunning($locale, $runningId);
            }

            return [$this->store->create($userId, $locale, $plan, CarbonImmutable::now()), true];
        });

        if ($created) {
            $this->dispatch($batch['meta']['id'], array_keys(array_filter(
                $batch['chunks'],
                fn (array $chunk): bool => $chunk['status'] === ProjectTranslationGenerationChunkStatus::Queued->value,
            )));
        }

        $read = $this->store->read($batch['meta']['id'], CarbonImmutable::now());

        return ProjectTranslationGenerationStore::summary($read ?? $batch);
    }

    /**
     * The actor's batch for the language when it is still worth keeping
     * instead of generating again: running, or holding suggestions nobody has
     * reviewed yet.
     *
     * @return array<string, mixed>|null
     */
    private function current(int $userId, string $locale): ?array
    {
        $batchId = $this->store->activeBatchId($userId, $locale);
        $batch = $batchId !== null ? $this->store->read($batchId, CarbonImmutable::now()) : null;

        if ($batch === null) {
            return null;
        }

        $summary = ProjectTranslationGenerationStore::summary($batch);

        return $batch['meta']['status'] !== ProjectTranslationGenerationStatus::Completed->value || $summary['counts']['ready'] > 0
            ? $summary
            : null;
    }

    /**
     * One job per chunk, on the application's own queue. A chunk whose job
     * could not be queued is failed at once, and so is every one after it,
     * so the batch never waits for work nobody will do.
     *
     * @param  list<string>  $chunkIds
     */
    private function dispatch(string $batchId, array $chunkIds): void
    {
        foreach ($chunkIds as $index => $chunkId) {
            try {
                GenerateProjectTranslationChunkJob::dispatch($batchId, $chunkId);
            } catch (Throwable) {
                foreach (array_slice($chunkIds, $index) as $undispatched) {
                    $this->store->fail($batchId, $undispatched, ProjectTranslationGenerationIssue::DispatchFailed, CarbonImmutable::now());
                }

                throw CannotGenerateTranslationsException::becauseItCouldNotBeQueued();
            }
        }
    }
}
