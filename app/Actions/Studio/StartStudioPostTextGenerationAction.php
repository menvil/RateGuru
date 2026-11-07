<?php

namespace App\Actions\Studio;

use App\Enums\StudioPostTextGenerationStatus;
use App\Models\StudioPlanTextGeneration;
use App\Support\Observability\DomainLogger;
use Carbon\CarbonImmutable;

/**
 * A worker claims a queued batch of post texts before doing anything else
 * with it: Queued becomes Running in one statement, and only for that
 * attempt id. A job delivered twice, a job of a batch ended since, or one
 * whose plan has gone claims nothing — and so sends nothing.
 */
final class StartStudioPostTextGenerationAction
{
    public function __construct(private readonly DomainLogger $logger) {}

    /** @return StudioPlanTextGeneration|null the batch, now running; null when there was nothing to claim */
    public function handle(int $generationId, string $attemptId): ?StudioPlanTextGeneration
    {
        $claimed = StudioPlanTextGeneration::query()
            ->whereKey($generationId)
            ->where('attempt_id', $attemptId)
            ->where('status', StudioPostTextGenerationStatus::Queued->value)
            ->update([
                'status' => StudioPostTextGenerationStatus::Running->value,
                'started_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]) === 1;

        if (! $claimed) {
            $this->logger->info('studio.post_text_generation.discarded', [
                'studio_plan_id' => StudioPlanTextGeneration::query()->whereKey($generationId)->value('studio_plan_id'),
                'attempt_id' => $attemptId,
                'reason' => 'not_claimed',
            ]);

            return null;
        }

        // The attempt this claimed, and only while it still runs: a claim held
        // up until it went stale can be failed and the batch asked for again
        // before this read — an attempt this job must not pay to write.
        $generation = StudioPlanTextGeneration::query()
            ->whereKey($generationId)
            ->where('attempt_id', $attemptId)
            ->where('status', StudioPostTextGenerationStatus::Running->value)
            ->first();

        if ($generation !== null) {
            $this->logger->info('studio.post_text_generation.started', [
                'studio_plan_id' => $generation->studio_plan_id,
                'attempt_id' => $attemptId,
                'tone' => $generation->tone->value,
                'status' => $generation->status->value,
            ]);
        }

        return $generation;
    }
}
