<?php

namespace App\Support\Translations\Generation;

use App\Exceptions\Translations\CannotGenerateTranslationsException;
use Carbon\CarbonImmutable;

/**
 * The background generations there are now, for a technical view of them:
 * the one generating each installed language, whoever started it, and the
 * viewer's own for each language, kept until it expires.
 *
 * Read through the store's pointers for a known list of languages — one
 * read for every running pointer, one for every pointer of the viewer, then
 * each batch they name — never by searching the store for keys. A
 * generation is temporary workflow state, so what is not pointed at any more
 * is gone, and there is no history to show.
 *
 * Reading a batch is the store's own read: one a worker abandoned is failed
 * as interrupted on the way, exactly as Translation Center would find it.
 */
final class ProjectTranslationGenerationMonitor
{
    public function __construct(private readonly ProjectTranslationGenerationStore $store) {}

    /**
     * Running generations first, in the order of $locales, then the viewer's
     * own others.
     *
     * @param  list<string>  $locales
     * @return list<ProjectTranslationGenerationProgress>
     *
     * @throws CannotGenerateTranslationsException when the store cannot be read
     */
    public function current(array $locales, ?int $viewerId, CarbonImmutable $now): array
    {
        $running = $this->store->runningBatchIds($locales);
        $own = $viewerId !== null ? $this->store->activeBatchIds($viewerId, $locales) : [];
        $generations = [];

        foreach ([...array_values($running), ...array_values($own)] as $batchId) {
            if (array_key_exists($batchId, $generations)) {
                continue;
            }

            $batch = $this->store->read($batchId, $now);

            if ($batch === null) {
                continue;
            }

            $progress = ProjectTranslationGenerationStore::progress($batch);

            $generations[$batchId] = new ProjectTranslationGenerationProgress(
                locale: $progress['locale'],
                status: ProjectTranslationGenerationStatus::from($progress['status']),
                total: $progress['total'],
                processed: $progress['total'] - $progress['pending'],
                ready: $progress['ready'],
                failed: $progress['failed'],
                skipped: $progress['skipped'],
                saved: $progress['saved'],
                discarded: $progress['discarded'],
                createdAt: CarbonImmutable::parse($progress['created_at'])->utc(),
                expiresAt: CarbonImmutable::parse($progress['expires_at'])->utc(),
                completedAt: $progress['completed_at'] !== null ? CarbonImmutable::parse($progress['completed_at'])->utc() : null,
                running: in_array($batchId, $running, true),
                startedByViewer: $viewerId !== null && $batch['meta']['user_id'] === $viewerId,
            );
        }

        return array_values($generations);
    }
}
