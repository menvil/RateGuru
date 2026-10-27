<?php

namespace App\Actions\Studio;

use App\Enums\StudioDiscussionGenerationStatus;
use App\Models\StudioPlanDiscussionGeneration;
use App\Support\Observability\DomainLogger;
use Carbon\CarbonImmutable;

/**
 * A worker claims a queued discussion generation before doing anything else
 * with it: Queued becomes Running in one statement, and only for that
 * attempt id. A job delivered twice, a job of a generation ended since, or
 * one whose plan has gone claims nothing — and so sends nothing.
 */
final class StartStudioDiscussionGenerationAction
{
    public function __construct(private readonly DomainLogger $logger) {}

    /** @return StudioPlanDiscussionGeneration|null the generation, now running; null when there was nothing to claim */
    public function handle(int $generationId, string $attemptId): ?StudioPlanDiscussionGeneration
    {
        $claimed = StudioPlanDiscussionGeneration::query()
            ->whereKey($generationId)
            ->where('attempt_id', $attemptId)
            ->where('status', StudioDiscussionGenerationStatus::Queued->value)
            ->update([
                'status' => StudioDiscussionGenerationStatus::Running->value,
                'started_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]) === 1;

        if (! $claimed) {
            $this->logger->info('studio.discussion_generation.discarded', [
                'studio_plan_id' => StudioPlanDiscussionGeneration::query()->whereKey($generationId)->value('studio_plan_id'),
                'generation_id' => $generationId,
                'attempt_id' => $attemptId,
                'reason' => 'not_claimed',
            ]);

            return null;
        }

        $generation = StudioPlanDiscussionGeneration::query()->find($generationId);

        if ($generation !== null) {
            $this->logger->info('studio.discussion_generation.started', [
                'studio_plan_id' => $generation->studio_plan_id,
                'generation_id' => $generation->id,
                'attempt_id' => $attemptId,
                'mode' => $generation->mode->value,
                'target_node_id' => $generation->targetNodeId(),
                'status' => $generation->status->value,
            ]);
        }

        return $generation;
    }
}
