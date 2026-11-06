<?php

namespace App\Actions\Studio;

use App\Enums\StudioImageAnalysisError;
use App\Enums\StudioImageAnalysisStatus;
use App\Models\StudioPlanImageAnalysis;
use App\Support\Observability\DomainLogger;
use App\Support\StudioAi\StudioImageAnalysisCall;
use Carbon\CarbonImmutable;

/**
 * Ends a photo analysis attempt that gave no result, with the reason — only
 * that attempt, and only while it is still waiting or running: one statement
 * that matches nothing once the attempt has finished, has been replaced by a
 * newer one, or has gone with its plan. The last result for the photo, when
 * there is one, stays as it was, and the plan is never touched.
 */
final class FailStudioImageAnalysisAction
{
    public function __construct(private readonly DomainLogger $logger) {}

    /**
     * @param  StudioImageAnalysisCall|null  $call  the request that was made, when one was
     * @return bool whether this attempt was ended now
     */
    public function handle(int $analysisId, string $attemptId, StudioImageAnalysisError $error, ?StudioImageAnalysisCall $call = null): bool
    {
        $planId = StudioPlanImageAnalysis::query()->whereKey($analysisId)->value('studio_plan_id');

        $failed = StudioPlanImageAnalysis::query()
            ->whereKey($analysisId)
            ->where('attempt_id', $attemptId)
            ->whereIn('status', [StudioImageAnalysisStatus::Queued->value, StudioImageAnalysisStatus::Running->value])
            ->update([
                'status' => StudioImageAnalysisStatus::Failed->value,
                'error_code' => $error->value,
                'updated_at' => CarbonImmutable::now(),
            ]) === 1;

        if ($failed) {
            $this->logger->warning('studio.image_analysis.failed', [
                'studio_plan_id' => $planId,
                'attempt_id' => $attemptId,
                'status' => StudioImageAnalysisStatus::Failed->value,
                'error_code' => $error->value,
                ...($call?->logContext() ?? []),
            ]);
        }

        return $failed;
    }
}
