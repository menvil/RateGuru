<?php

namespace App\Support\Translations\Generation;

use App\Exceptions\Translations\CannotGenerateTranslationsException;
use App\Exceptions\Translations\CannotSuggestTranslationException;
use App\Support\TranslationEngine\Data\TranslationItem;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use Throwable;

/**
 * Where background AI suggestions wait for review: temporary workflow state in
 * a cache store (translation.bulk.cache_store — Redis on a deployed target),
 * never project content and never the database.
 *
 * Keys, all under the cache's own per-target prefix and none carrying text:
 *
 *   translation-generation:active:{user}:{locale}      the batch an administrator works on in a language
 *   translation-generation:running:{locale}            the batch generating that language now, if any
 *   translation-generation:batch:{uuid}                small metadata: owner, language, provider plan, chunk ids
 *   translation-generation:batch:{uuid}:chunk:{id}     one provider request's items: snapshots, then results
 *
 * Values are plain arrays of strings, integers, booleans and null — the cache
 * unserializes no objects. Enums are stored by value, times as ISO-8601.
 *
 * A batch expires translation.bulk.ttl_seconds after it was created, and every
 * key of it with it: each write sets the same absolute expiry, and reading
 * sets none, so a page left open never keeps drafts alive.
 *
 * Every change of state happens under a short lock on the batch, and nothing
 * slow happens under it: a job claims its chunk under the lock, lets go, calls
 * the provider, and takes the lock again to store the answer. Only a queued
 * chunk can be claimed, so a job delivered twice sends nothing the second
 * time. A chunk a worker claimed and never finished is failed as interrupted
 * once translation.bulk.stale_running_seconds have passed, the next time the
 * batch is read.
 *
 * When the store cannot be reached, every operation says so as a
 * CannotGenerateTranslationsException — never as a server error — and nothing
 * else in Translation Center depends on it.
 *
 * @phpstan-type Item array{id: string, fingerprint: string, snapshot: array<string, mixed>, status: string, text: ?string, provider: ?string, model: ?string, generated_at: ?string, issue: ?string, message: ?string}
 * @phpstan-type Chunk array{id: string, status: string, issue: ?string, target_locale: string, classification: string, glossary: array<array-key, mixed>, running_started_at: ?string, finished_at: ?string, items: list<Item>}
 * @phpstan-type Meta array{id: string, user_id: int, target_locale: string, status: string, planned_provider: string, provider_max_items: int, provider_max_payload_chars: int, chunk_ids: list<string>, total: int, created_at: string, expires_at: string, completed_at: ?string, version: int}
 * @phpstan-type Batch array{meta: Meta, chunks: array<string, Chunk>}
 */
final class ProjectTranslationGenerationStore
{
    private const PREFIX = 'translation-generation';

    private const LOCK_SECONDS = 15;

    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(private readonly CacheFactory $caches) {}

    // Creating ---------------------------------------------------------------------

    /**
     * Stores a new batch from its plan and makes it the owner's batch for the
     * language, and the language's running one while anything of it is
     * queued. The caller holds the language's start lock (lockLanguage()).
     *
     * @return Batch
     */
    public function create(int $userId, string $locale, ProjectTranslationGenerationPlan $plan, CarbonImmutable $now): array
    {
        $id = (string) Str::uuid();
        $expires = $now->addSeconds($this->ttlSeconds());
        $chunks = [];

        foreach ($plan->chunks as $index => $request) {
            $chunkId = 'c'.($index + 1);
            $chunks[$chunkId] = self::chunk($chunkId, $plan, $request->items);
        }

        if ($plan->oversized !== []) {
            // Too large for any request on their own: never sent, failed from the start.
            $chunk = self::chunk('oversized', $plan, $plan->oversized);
            $chunk['status'] = ProjectTranslationGenerationChunkStatus::Completed->value;
            $chunk['finished_at'] = $now->toIso8601String();

            foreach ($chunk['items'] as $index => $item) {
                $chunk['items'][$index] = self::outcome($item, ProjectTranslationGenerationItemStatus::Failed, issue: TranslationErrorCode::RequestTooLarge->value, message: CannotSuggestTranslationException::messageFor(TranslationErrorCode::RequestTooLarge));
            }

            $chunks['oversized'] = $chunk;
        }

        $meta = [
            'id' => $id,
            'user_id' => $userId,
            'target_locale' => $locale,
            'status' => ProjectTranslationGenerationStatus::Queued->value,
            'planned_provider' => $plan->provider,
            'provider_max_items' => $plan->maxItems,
            'provider_max_payload_chars' => $plan->maxProviderVisibleChars,
            'chunk_ids' => array_keys($chunks),
            'total' => array_sum(array_map(fn (array $chunk): int => count($chunk['items']), $chunks)),
            'created_at' => $now->toIso8601String(),
            'expires_at' => $expires->toIso8601String(),
            'completed_at' => null,
            'version' => 0,
        ];

        $batch = $this->persist(['meta' => $meta, 'chunks' => $chunks], array_keys($chunks), $now);

        $this->put(self::activeKey($userId, $locale), $id, $expires);

        if ($batch['meta']['status'] !== ProjectTranslationGenerationStatus::Completed->value) {
            $this->put(self::runningKey($locale), $id, $expires);
        }

        return $batch;
    }

    // Pointers -----------------------------------------------------------------------

    public function activeBatchId(int $userId, string $locale): ?string
    {
        $id = $this->get(self::activeKey($userId, $locale));

        return is_string($id) ? $id : null;
    }

    public function runningBatchId(string $locale): ?string
    {
        $id = $this->get(self::runningKey($locale));

        return is_string($id) ? $id : null;
    }

    /** Forgets an administrator's batch for a language — only if it is still this one. */
    public function forgetActive(int $userId, string $locale, string $batchId): void
    {
        if ($this->activeBatchId($userId, $locale) === $batchId) {
            $this->forget(self::activeKey($userId, $locale));
        }
    }

    public function forgetRunning(string $locale, string $batchId): void
    {
        if ($this->runningBatchId($locale) === $batchId) {
            $this->forget(self::runningKey($locale));
        }
    }

    // Reading -------------------------------------------------------------------------

    /**
     * A batch as it is now, with any chunk a worker abandoned failed as
     * interrupted, or null when it has expired or never existed.
     *
     * @return Batch|null
     */
    public function read(string $batchId, CarbonImmutable $now): ?array
    {
        $batch = $this->load($batchId);

        if ($batch === null || $this->staleChunkIds($batch, $now) === []) {
            return $batch;
        }

        return $this->locked($batchId, function () use ($batchId, $now): ?array {
            $batch = $this->load($batchId);

            if ($batch === null) {
                return null;
            }

            $stale = $this->staleChunkIds($batch, $now);

            foreach ($stale as $chunkId) {
                $batch['chunks'][$chunkId] = self::failed($batch['chunks'][$chunkId], ProjectTranslationGenerationIssue::WorkerInterrupted, $now);
            }

            return $stale === [] ? $batch : $this->persist($batch, $stale, $now);
        });
    }

    // A chunk's job ------------------------------------------------------------------------

    /**
     * Claims a queued chunk for one job: queued becomes running, under the
     * batch's lock. Null — and nothing to send — when the batch is gone or the
     * chunk is already running or done, as for a job delivered twice.
     *
     * @return array{meta: Meta, chunk: Chunk}|null
     */
    public function claim(string $batchId, string $chunkId, CarbonImmutable $now): ?array
    {
        return $this->locked($batchId, function () use ($batchId, $chunkId, $now): ?array {
            $batch = $this->load($batchId);
            $chunk = $batch['chunks'][$chunkId] ?? null;

            if ($batch === null || $chunk === null || $chunk['status'] !== ProjectTranslationGenerationChunkStatus::Queued->value) {
                return null;
            }

            $chunk['status'] = ProjectTranslationGenerationChunkStatus::Running->value;
            $chunk['running_started_at'] = $now->toIso8601String();

            foreach ($chunk['items'] as $index => $item) {
                $chunk['items'][$index]['status'] = ProjectTranslationGenerationItemStatus::Running->value;
            }

            $batch['chunks'][$chunkId] = $chunk;
            $batch = $this->persist($batch, [$chunkId], $now);

            return ['meta' => $batch['meta'], 'chunk' => $batch['chunks'][$chunkId]];
        });
    }

    /**
     * Stores what a chunk's job found: per item, a ready suggestion, a failure
     * or a skip. Kept even when the chunk was given up as interrupted in the
     * meantime — the request was made, and paid for.
     *
     * @param  array<string, array{status: ProjectTranslationGenerationItemStatus, text?: ?string, provider?: ?string, model?: ?string, issue?: ?string, message?: ?string}>  $outcomes  by unit id
     */
    public function complete(string $batchId, string $chunkId, array $outcomes, CarbonImmutable $now): void
    {
        $this->locked($batchId, function () use ($batchId, $chunkId, $outcomes, $now): void {
            $batch = $this->load($batchId);
            $chunk = $batch['chunks'][$chunkId] ?? null;

            if ($batch === null || $chunk === null) {
                return;
            }

            foreach ($chunk['items'] as $index => $item) {
                $outcome = $outcomes[$item['id']] ?? null;

                $chunk['items'][$index] = $outcome === null
                    ? self::outcome($item, ProjectTranslationGenerationItemStatus::Failed, issue: ProjectTranslationGenerationIssue::JobFailed->value, message: ProjectTranslationGenerationIssue::JobFailed->message())
                    : self::outcome(
                        $item,
                        $outcome['status'],
                        text: $outcome['text'] ?? null,
                        provider: $outcome['provider'] ?? null,
                        model: $outcome['model'] ?? null,
                        generatedAt: $outcome['status'] === ProjectTranslationGenerationItemStatus::Ready ? $now->toIso8601String() : null,
                        issue: $outcome['issue'] ?? null,
                        message: $outcome['message'] ?? null,
                    );
            }

            $chunk['status'] = ProjectTranslationGenerationChunkStatus::Completed->value;
            $chunk['issue'] = null;
            $chunk['finished_at'] = $now->toIso8601String();
            $batch['chunks'][$chunkId] = $chunk;

            $this->persist($batch, [$chunkId], $now);
        });
    }

    /** Fails a chunk that has not finished, and every item of it still waiting, for one safe reason. */
    public function fail(string $batchId, string $chunkId, ProjectTranslationGenerationIssue $issue, CarbonImmutable $now): void
    {
        $this->locked($batchId, function () use ($batchId, $chunkId, $issue, $now): void {
            $batch = $this->load($batchId);
            $chunk = $batch['chunks'][$chunkId] ?? null;

            if ($batch === null || $chunk === null || ProjectTranslationGenerationChunkStatus::from($chunk['status'])->isTerminal()) {
                return;
            }

            $batch['chunks'][$chunkId] = self::failed($chunk, $issue, $now);
            $this->persist($batch, [$chunkId], $now);
        });
    }

    // An administrator's decisions -----------------------------------------------------

    /**
     * Records what became of ready suggestions — saved, discarded, or skipped
     * because their English or their translation changed. An item that is no
     * longer ready is left as it is.
     *
     * @param  array<string, array{status: ProjectTranslationGenerationItemStatus, issue?: ?string, message?: ?string}>  $outcomes  by unit id
     * @return Batch|null
     */
    public function resolve(string $batchId, array $outcomes, CarbonImmutable $now): ?array
    {
        return $this->locked($batchId, function () use ($batchId, $outcomes, $now): ?array {
            $batch = $this->load($batchId);

            if ($batch === null) {
                return null;
            }

            $changed = [];

            foreach ($batch['chunks'] as $chunkId => $chunk) {
                foreach ($chunk['items'] as $index => $item) {
                    $outcome = $outcomes[$item['id']] ?? null;

                    if ($outcome === null || $item['status'] !== ProjectTranslationGenerationItemStatus::Ready->value) {
                        continue;
                    }

                    $resolved = self::outcome($item, $outcome['status'], issue: $outcome['issue'] ?? null, message: $outcome['message'] ?? null);

                    // A saved suggestion keeps its text: it is what was written.
                    if ($outcome['status'] === ProjectTranslationGenerationItemStatus::Saved) {
                        $resolved['text'] = $item['text'];
                        $resolved['provider'] = $item['provider'];
                        $resolved['model'] = $item['model'];
                        $resolved['generated_at'] = $item['generated_at'];
                    }

                    $batch['chunks'][$chunkId]['items'][$index] = $resolved;
                    $changed[$chunkId] = true;
                }
            }

            return $changed === [] ? $batch : $this->persist($batch, array_keys($changed), $now);
        });
    }

    // Presentation ---------------------------------------------------------------------

    /**
     * The batch as the browser may see it: status, counts, and per item what
     * it needs to restore or explain a row — the text only of a ready
     * suggestion, a safe message only of a failure or a skip. Never the
     * snapshot, the context or another language.
     *
     * @param  Batch  $batch
     * @return array<string, mixed>
     */
    public static function summary(array $batch): array
    {
        $counts = ['total' => 0, 'pending' => 0, 'ready' => 0, 'failed' => 0, 'skipped' => 0, 'saved' => 0, 'discarded' => 0];
        $items = [];

        foreach ($batch['meta']['chunk_ids'] as $chunkId) {
            foreach ($batch['chunks'][$chunkId]['items'] ?? [] as $item) {
                $status = ProjectTranslationGenerationItemStatus::from($item['status']);
                $counts['total']++;
                $counts[$status->isPending() ? 'pending' : $status->value]++;

                $items[] = array_filter([
                    'unit' => $item['id'],
                    'status' => $status->isPending() ? 'pending' : $status->value,
                    'fingerprint' => $item['fingerprint'],
                    'text' => $status === ProjectTranslationGenerationItemStatus::Ready ? $item['text'] : null,
                    'generatedAt' => $status === ProjectTranslationGenerationItemStatus::Ready ? $item['generated_at'] : null,
                    'issue' => in_array($status, [ProjectTranslationGenerationItemStatus::Failed, ProjectTranslationGenerationItemStatus::Skipped], true) ? $item['issue'] : null,
                    'message' => in_array($status, [ProjectTranslationGenerationItemStatus::Failed, ProjectTranslationGenerationItemStatus::Skipped], true) ? $item['message'] : null,
                ], fn (mixed $value): bool => $value !== null);
            }
        }

        return [
            'batch' => $batch['meta']['id'],
            'status' => $batch['meta']['status'],
            'version' => $batch['meta']['version'],
            'createdAt' => $batch['meta']['created_at'],
            'expiresAt' => $batch['meta']['expires_at'],
            'counts' => $counts,
            'items' => $items,
        ];
    }

    /**
     * Every item of a batch with the chunk it is in, in batch order.
     *
     * @param  Batch  $batch
     * @return list<Item>
     */
    public static function items(array $batch): array
    {
        $items = [];

        foreach ($batch['meta']['chunk_ids'] as $chunkId) {
            foreach ($batch['chunks'][$chunkId]['items'] ?? [] as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    // Locks ---------------------------------------------------------------------------

    /**
     * Runs $callback holding a language's start lock, so two administrators
     * starting the same language cannot both find nothing running.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function lockLanguage(string $locale, Closure $callback): mixed
    {
        return $this->withLock(self::PREFIX.":lock:language:{$locale}", $callback);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(string $batchId, Closure $callback): mixed
    {
        return $this->withLock(self::PREFIX.":lock:batch:{$batchId}", $callback);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withLock(string $name, Closure $callback): mixed
    {
        $lock = $this->guard(function () use ($name): Lock {
            $store = $this->cache()->getStore();

            if (! $store instanceof LockProvider) {
                throw CannotGenerateTranslationsException::becauseTheStoreIsUnavailable();
            }

            $lock = $store->lock($name, self::LOCK_SECONDS);
            $lock->block(self::LOCK_WAIT_SECONDS);

            return $lock;
        });

        try {
            return $callback();
        } finally {
            $this->guard(fn () => $lock->release());
        }
    }

    // Storage --------------------------------------------------------------------------

    /**
     * @return Batch|null
     */
    private function load(string $batchId): ?array
    {
        $meta = $this->get(self::batchKey($batchId));

        if (! is_array($meta) || ! isset($meta['chunk_ids']) || ! is_array($meta['chunk_ids'])) {
            return null;
        }

        $keys = array_map(fn (string $chunkId): string => self::chunkKey($batchId, $chunkId), $meta['chunk_ids']);
        $values = $this->guard(fn (): iterable => $this->cache()->many($keys));
        $chunks = [];

        foreach ($meta['chunk_ids'] as $chunkId) {
            $chunk = $values[self::chunkKey($batchId, $chunkId)] ?? null;

            if (is_array($chunk)) {
                $chunks[$chunkId] = $chunk;
            }
        }

        /** @var Batch */
        return ['meta' => $meta, 'chunks' => $chunks];
    }

    /**
     * Writes the batch's metadata and the chunks that changed, with the
     * batch's own fixed expiry, recomputing its status — and, once nothing is
     * left to run, completing it and letting the language run another.
     *
     * @param  Batch  $batch
     * @param  list<string>  $changedChunkIds
     * @return Batch
     */
    private function persist(array $batch, array $changedChunkIds, CarbonImmutable $now): array
    {
        $meta = $batch['meta'];
        $status = self::statusOf($batch['chunks']);
        $expires = CarbonImmutable::parse($meta['expires_at']);

        $meta['status'] = $status->value;
        $meta['version']++;

        if ($status === ProjectTranslationGenerationStatus::Completed && $meta['completed_at'] === null) {
            $meta['completed_at'] = $now->toIso8601String();
        }

        $values = [self::batchKey($meta['id']) => $meta];

        foreach ($changedChunkIds as $chunkId) {
            $values[self::chunkKey($meta['id'], $chunkId)] = $batch['chunks'][$chunkId];
        }

        $this->guard(fn () => $this->cache()->putMany($values, $expires));

        if ($status === ProjectTranslationGenerationStatus::Completed) {
            $this->forgetRunning($meta['target_locale'], $meta['id']);
        }

        return ['meta' => $meta, 'chunks' => $batch['chunks']];
    }

    /** @param  array<string, Chunk>  $chunks */
    private static function statusOf(array $chunks): ProjectTranslationGenerationStatus
    {
        $queued = 0;
        $terminal = 0;

        foreach ($chunks as $chunk) {
            $status = ProjectTranslationGenerationChunkStatus::from($chunk['status']);
            $queued += $status === ProjectTranslationGenerationChunkStatus::Queued ? 1 : 0;
            $terminal += $status->isTerminal() ? 1 : 0;
        }

        if ($terminal === count($chunks)) {
            return ProjectTranslationGenerationStatus::Completed;
        }

        return $queued === count($chunks) ? ProjectTranslationGenerationStatus::Queued : ProjectTranslationGenerationStatus::Running;
    }

    /**
     * @param  Batch  $batch
     * @return list<string>
     */
    private function staleChunkIds(array $batch, CarbonImmutable $now): array
    {
        $limit = $now->subSeconds($this->staleSeconds());
        $stale = [];

        foreach ($batch['chunks'] as $chunkId => $chunk) {
            if ($chunk['status'] === ProjectTranslationGenerationChunkStatus::Running->value
                && $chunk['running_started_at'] !== null
                && CarbonImmutable::parse($chunk['running_started_at'])->lessThan($limit)) {
                $stale[] = $chunkId;
            }
        }

        return $stale;
    }

    /**
     * @param  list<TranslationItem>  $items
     * @return Chunk
     */
    private static function chunk(string $id, ProjectTranslationGenerationPlan $plan, array $items): array
    {
        return [
            'id' => $id,
            'status' => ProjectTranslationGenerationChunkStatus::Queued->value,
            'issue' => null,
            'target_locale' => $plan->targetLocale,
            'classification' => $plan->classification,
            'glossary' => $plan->glossary,
            'running_started_at' => null,
            'finished_at' => null,
            'items' => array_map(fn (TranslationItem $item): array => [
                'id' => $item->id,
                'fingerprint' => ProjectTranslationSourceFingerprint::for($item->id, $item->sourceText),
                'snapshot' => ProjectTranslationGenerationSnapshot::item($item),
                'status' => ProjectTranslationGenerationItemStatus::Queued->value,
                'text' => null,
                'provider' => null,
                'model' => null,
                'generated_at' => null,
                'issue' => null,
                'message' => null,
            ], $items),
        ];
    }

    /**
     * @param  Chunk  $chunk
     * @return Chunk
     */
    private static function failed(array $chunk, ProjectTranslationGenerationIssue $issue, CarbonImmutable $now): array
    {
        $chunk['status'] = ProjectTranslationGenerationChunkStatus::Failed->value;
        $chunk['issue'] = $issue->value;
        $chunk['finished_at'] = $now->toIso8601String();

        foreach ($chunk['items'] as $index => $item) {
            if (ProjectTranslationGenerationItemStatus::from($item['status'])->isPending()) {
                $chunk['items'][$index] = self::outcome($item, ProjectTranslationGenerationItemStatus::Failed, issue: $issue->value, message: $issue->message());
            }
        }

        return $chunk;
    }

    /**
     * @param  Item  $item
     * @return Item
     */
    private static function outcome(
        array $item,
        ProjectTranslationGenerationItemStatus $status,
        ?string $text = null,
        ?string $provider = null,
        ?string $model = null,
        ?string $generatedAt = null,
        ?string $issue = null,
        ?string $message = null,
    ): array {
        return [
            ...$item,
            'status' => $status->value,
            'text' => $text,
            'provider' => $provider,
            'model' => $model,
            'generated_at' => $generatedAt,
            'issue' => $issue,
            'message' => $message,
        ];
    }

    private static function activeKey(int $userId, string $locale): string
    {
        return self::PREFIX.":active:{$userId}:{$locale}";
    }

    private static function runningKey(string $locale): string
    {
        return self::PREFIX.":running:{$locale}";
    }

    private static function batchKey(string $batchId): string
    {
        return self::PREFIX.":batch:{$batchId}";
    }

    private static function chunkKey(string $batchId, string $chunkId): string
    {
        return self::PREFIX.":batch:{$batchId}:chunk:{$chunkId}";
    }

    private function get(string $key): mixed
    {
        return $this->guard(fn (): mixed => $this->cache()->get($key));
    }

    private function put(string $key, mixed $value, CarbonImmutable $expires): void
    {
        $this->guard(fn () => $this->cache()->put($key, $value, $expires));
    }

    private function forget(string $key): void
    {
        $this->guard(fn () => $this->cache()->forget($key));
    }

    private function cache(): Repository
    {
        return $this->caches->store((string) config('translation.bulk.cache_store'));
    }

    private function ttlSeconds(): int
    {
        return max(1, (int) config('translation.bulk.ttl_seconds'));
    }

    private function staleSeconds(): int
    {
        return max(1, (int) config('translation.bulk.stale_running_seconds'));
    }

    /**
     * Runs one operation on the store, turning any failure of the store into
     * the one refusal callers handle — never a server error.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function guard(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (CannotGenerateTranslationsException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CannotGenerateTranslationsException::becauseTheStoreIsUnavailable();
        }
    }
}
