<?php

namespace App\Actions\Studio;

use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationStatus;
use App\Models\StudioPlanDiscussionGeneration;
use App\Support\Observability\DomainLogger;
use App\Support\StudioAi\StudioDiscussionGenerationCall;
use Carbon\CarbonImmutable;

/**
 * Ends a discussion generation that gave no proposal, with the reason — only
 * that generation, and only while it is still waiting or running: one
 * statement that matches nothing once it has finished or gone with its plan.
 * Earlier generations, the plan and its discussion are never touched.
 */
final class FailStudioDiscussionGenerationAction
{
    public function __construct(private readonly DomainLogger $logger) {}

    /**
     * @param  StudioDiscussionGenerationCall|null  $call  the request that was made, when one was
     * @return bool whether this generation was ended now
     */
    public function handle(int $generationId, string $attemptId, StudioDiscussionGenerationError $error, ?StudioDiscussionGenerationCall $call = null): bool
    {
        $generation = StudioPlanDiscussionGeneration::query()->whereKey($generationId)->first(['id', 'studio_plan_id', 'mode', 'parameters']);

        $failed = StudioPlanDiscussionGeneration::query()
            ->whereKey($generationId)
            ->where('attempt_id', $attemptId)
            ->whereIn('status', [StudioDiscussionGenerationStatus::Queued->value, StudioDiscussionGenerationStatus::Running->value])
            ->update([
                'status' => StudioDiscussionGenerationStatus::Failed->value,
                'error_code' => $error->value,
                'finished_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
                ...($call?->attributes() ?? []),
            ]) === 1;

        if ($failed) {
            $this->logger->warning('studio.discussion_generation.failed', [
                'studio_plan_id' => $generation?->studio_plan_id,
                'generation_id' => $generationId,
                'attempt_id' => $attemptId,
                'mode' => $generation?->mode->value,
                'target_node_id' => $generation?->targetNodeId(),
                'status' => StudioDiscussionGenerationStatus::Failed->value,
                'error_code' => $error->value,
                ...($call?->logContext() ?? []),
            ]);
        }

        return $failed;
    }
}
