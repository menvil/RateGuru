<?php

namespace App\Jobs\Translations;

use App\Exceptions\Translations\CannotGenerateTranslationsException;
use App\Exceptions\Translations\CannotSuggestTranslationException;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationBatchResult;
use App\Support\TranslationEngine\Data\TranslationItem;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Exceptions\InvalidTranslationRequestException;
use App\Support\TranslationEngine\Exceptions\TranslationConfigurationException;
use App\Support\TranslationEngine\TranslationService;
use App\Support\Translations\Generation\ProjectTranslationGenerationIssue;
use App\Support\Translations\Generation\ProjectTranslationGenerationItemStatus;
use App\Support\Translations\Generation\ProjectTranslationGenerationPlanner;
use App\Support\Translations\Generation\ProjectTranslationGenerationSnapshot;
use App\Support\Translations\Generation\ProjectTranslationGenerationStore;
use App\Support\Translations\Generation\ProjectTranslationSourceFingerprint;
use App\Support\Translations\ProjectTranslationCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Generates one chunk of a Generate missing batch: one provider request, at
 * most, through TranslationService — never a provider directly.
 *
 * It carries only the batch and chunk ids. What it sends is the snapshot the
 * batch was planned from, sized to fit one request of the provider it was
 * planned for; so one job is at most one paid call. The timings nest:
 *
 *   provider request timeout   45 s
 *   this job's timeout         75 s   its own, ahead of the worker's
 *   Redis queue retry_after    90 s
 *   queue worker timeout      120 s
 *
 * The job is stopped well before Redis would hand it to another worker as if
 * it had been lost, so a reserved job is never run twice at once. Exactly
 * once, at most: a job claims its chunk before anything else, and only a
 * queued chunk can be claimed, so a job delivered twice sends nothing the
 * second time. It is never retried: a retry may be a second paid call, and
 * generating again is the administrator's choice.
 *
 * Before sending, it checks that nothing it was planned on has changed:
 *
 *  - whoever started the batch may still manage project settings;
 *  - the configured provider and its limits are the ones planned for;
 *  - each item is still in the catalog, still has English, still has the
 *    English it was planned from (by fingerprint), and is still missing.
 *
 * An item that fails a check is skipped and never sent; a chunk whose
 * provider changed is not sent at all. Whatever comes back is stored as a
 * ready suggestion or a failure per item, in the store, never as project
 * content. The batch lock is held only while state changes — never across
 * the provider call.
 */
final class GenerateProjectTranslationChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $batchId,
        public readonly string $chunkId,
    ) {}

    public function handle(
        ProjectTranslationGenerationStore $store,
        ProjectTranslationCatalog $catalog,
        ProjectTranslationGenerationPlanner $planner,
        TranslationService $translations,
    ): void {
        $claimed = $store->claim($this->batchId, $this->chunkId, CarbonImmutable::now());

        if ($claimed === null) {
            return;
        }

        $meta = $claimed['meta'];
        $chunk = $claimed['chunk'];
        // The installed language, for the catalog; the chunk keeps the tag its request names it by.
        $locale = $meta['target_locale'];
        $writtenAs = $chunk['target_locale'];

        $user = User::query()->find($meta['user_id']);

        if ($user === null || ! Gate::forUser($user)->allows('manage-project-settings')) {
            $this->failChunk($store, ProjectTranslationGenerationIssue::NotAllowed);

            return;
        }

        $snapshots = array_map(fn (array $item): array => $item['snapshot'], $chunk['items']);
        $planned = ProjectTranslationGenerationSnapshot::request($writtenAs, $chunk['classification'], $snapshots, $chunk['glossary']);

        try {
            $provider = $planner->current($planned);
        } catch (TranslationConfigurationException $exception) {
            $store->complete($this->batchId, $this->chunkId, $this->allFailed($chunk['items'], $exception->errorCode), CarbonImmutable::now());

            return;
        }

        if ($provider['provider'] !== $meta['planned_provider']
            || $provider['maxItems'] !== $meta['provider_max_items']
            || $provider['maxProviderVisibleChars'] !== $meta['provider_max_payload_chars']) {
            $this->failChunk($store, ProjectTranslationGenerationIssue::ProviderConfigurationChanged);

            return;
        }

        // Every item checked against the catalog as it is now, in one bounded read.
        $units = $catalog->findMany(array_map(fn (array $item): string => $item['id'], $chunk['items']));
        $outcomes = [];
        $send = [];

        foreach ($chunk['items'] as $item) {
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
            } else {
                $send[] = $item['snapshot'];
            }
        }

        if ($send !== []) {
            $outcomes += $this->translate($translations, ProjectTranslationGenerationSnapshot::request($writtenAs, $chunk['classification'], $send, $chunk['glossary']));
        }

        $store->complete($this->batchId, $this->chunkId, $outcomes, CarbonImmutable::now());
    }

    /**
     * The job ended without finishing — an exception, a timeout: whatever of
     * its chunk is still waiting fails, for a fixed reason, so the batch never
     * waits for it. Nothing the exception said is kept.
     */
    public function failed(?Throwable $exception = null): void
    {
        try {
            $store = app(ProjectTranslationGenerationStore::class);
            $store->fail($this->batchId, $this->chunkId, ProjectTranslationGenerationIssue::JobFailed, CarbonImmutable::now());
        } catch (CannotGenerateTranslationsException) {
            // The store is unreachable; the batch is given up when it is next read.
        }

        app(DomainLogger::class)->warning('translation.generation_chunk_failed', [
            'batch_id' => $this->batchId,
            'chunk_id' => $this->chunkId,
            'error_code' => ProjectTranslationGenerationIssue::JobFailed->value,
            'exception_class' => $exception !== null ? $exception::class : null,
        ]);
    }

    /**
     * One call to the translation engine, whose answer is per item.
     *
     * @return array<string, array{status: ProjectTranslationGenerationItemStatus, text?: ?string, provider?: ?string, model?: ?string, issue?: ?string, message?: ?string}>
     */
    private function translate(TranslationService $translations, TranslationBatchRequest $request): array
    {
        try {
            $result = $translations->translate($request);
        } catch (TranslationConfigurationException $exception) {
            return $this->allFailed(array_map(fn (TranslationItem $item): array => ['id' => $item->id], $request->items), $exception->errorCode);
        } catch (InvalidTranslationRequestException) {
            return $this->allFailed(array_map(fn (TranslationItem $item): array => ['id' => $item->id], $request->items), TranslationErrorCode::RequestTooLarge);
        }

        return $this->outcomesOf($result);
    }

    /**
     * @return array<string, array{status: ProjectTranslationGenerationItemStatus, text?: ?string, provider?: ?string, model?: ?string, issue?: ?string, message?: ?string}>
     */
    private function outcomesOf(TranslationBatchResult $result): array
    {
        $outcomes = [];

        foreach ($result->items as $item) {
            if ($item->isSuccessful() && $item->text !== null) {
                $call = null;

                foreach ($result->calls as $candidate) {
                    if (in_array($item->id, $candidate->itemIds, true)) {
                        $call = $candidate;

                        break;
                    }
                }

                $outcomes[$item->id] = ['status' => ProjectTranslationGenerationItemStatus::Ready, 'text' => $item->text, 'provider' => $call?->provider, 'model' => $call?->model];

                continue;
            }

            $code = $item->errorCode ?? TranslationErrorCode::InvalidProviderResponse;
            $outcomes[$item->id] = ['status' => ProjectTranslationGenerationItemStatus::Failed, 'issue' => $code->value, 'message' => CannotSuggestTranslationException::messageFor($code)];
        }

        return $outcomes;
    }

    /**
     * @param  array<int, array{id: string}>  $items
     * @return array<string, array{status: ProjectTranslationGenerationItemStatus, issue: string, message: string}>
     */
    private function allFailed(array $items, TranslationErrorCode $code): array
    {
        $outcomes = [];

        foreach ($items as $item) {
            $outcomes[$item['id']] = ['status' => ProjectTranslationGenerationItemStatus::Failed, 'issue' => $code->value, 'message' => CannotSuggestTranslationException::messageFor($code)];
        }

        return $outcomes;
    }

    private function failChunk(ProjectTranslationGenerationStore $store, ProjectTranslationGenerationIssue $issue): void
    {
        $store->fail($this->batchId, $this->chunkId, $issue, CarbonImmutable::now());

        app(DomainLogger::class)->warning('translation.generation_chunk_failed', [
            'batch_id' => $this->batchId,
            'chunk_id' => $this->chunkId,
            'error_code' => $issue->value,
        ]);
    }
}
