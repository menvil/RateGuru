<?php

namespace App\Actions\Studio;

use App\Enums\StudioPostTextGenerationError;
use App\Enums\StudioPostTextGenerationStatus;
use App\Models\StudioPlanTextGeneration;
use App\Support\Observability\DomainLogger;
use App\Support\StudioAi\StudioPostTextGenerationCall;
use Carbon\CarbonImmutable;

/**
 * Ends a batch of post texts that gave none, with the reason — only that
 * batch, and only while it is still waiting or running: one statement that
 * matches nothing once the batch has finished or gone with its plan. Earlier
 * batches, the plan, its photo and its final texts are never touched.
 */
final class FailStudioPostTextGenerationAction
{
    public function __construct(private readonly DomainLogger $logger) {}

    /**
     * @param  StudioPostTextGenerationCall|null  $call  the request that was made, when one was
     * @return bool whether this batch was ended now
     */
    public function handle(int $generationId, string $attemptId, StudioPostTextGenerationError $error, ?StudioPostTextGenerationCall $call = null): bool
    {
        $planId = StudioPlanTextGeneration::query()->whereKey($generationId)->value('studio_plan_id');

        $failed = StudioPlanTextGeneration::query()
            ->whereKey($generationId)
            ->where('attempt_id', $attemptId)
            ->whereIn('status', [StudioPostTextGenerationStatus::Queued->value, StudioPostTextGenerationStatus::Running->value])
            ->update([
                'status' => StudioPostTextGenerationStatus::Failed->value,
                'error_code' => $error->value,
                'finished_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
                ...($call !== null ? [
                    'model' => mb_substr($call->model, 0, 128),
                    'request_id' => $call->requestId !== null ? mb_substr($call->requestId, 0, 255) : null,
                    'input_tokens' => $call->inputTokens,
                    'output_tokens' => $call->outputTokens,
                    'total_tokens' => $call->totalTokens,
                    'latency_ms' => $call->latencyMs,
                ] : []),
            ]) === 1;

        if ($failed) {
            $this->logger->warning('studio.post_text_generation.failed', [
                'studio_plan_id' => $planId,
                'attempt_id' => $attemptId,
                'status' => StudioPostTextGenerationStatus::Failed->value,
                'error_code' => $error->value,
                ...($call?->logContext() ?? []),
            ]);
        }

        return $failed;
    }
}
