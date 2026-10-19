<?php

namespace App\Actions\Studio;

use App\Enums\StudioImageAnalysisStatus;
use App\Models\StudioPlanImageAnalysis;
use App\Support\Observability\DomainLogger;
use Carbon\CarbonImmutable;

/**
 * A worker claims a queued photo analysis attempt before doing anything else
 * with it: Queued becomes Running in one statement, and only for that attempt
 * id. A job delivered twice, a job of an attempt replaced since, or one whose
 * plan has gone claims nothing — and so sends nothing.
 */
final class StartStudioImageAnalysisAction
{
    public function __construct(private readonly DomainLogger $logger) {}

    /** @return StudioPlanImageAnalysis|null the attempt, now running; null when there was nothing to claim */
    public function handle(int $analysisId, string $attemptId): ?StudioPlanImageAnalysis
    {
        $claimed = StudioPlanImageAnalysis::query()
            ->whereKey($analysisId)
            ->where('attempt_id', $attemptId)
            ->where('status', StudioImageAnalysisStatus::Queued->value)
            ->update([
                'status' => StudioImageAnalysisStatus::Running->value,
                'started_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]) === 1;

        if (! $claimed) {
            $this->logger->info('studio.image_analysis.discarded', [
                'studio_plan_id' => StudioPlanImageAnalysis::query()->whereKey($analysisId)->value('studio_plan_id'),
                'attempt_id' => $attemptId,
                'reason' => 'not_claimed',
            ]);

            return null;
        }

        $analysis = StudioPlanImageAnalysis::query()->find($analysisId);

        if ($analysis !== null) {
            $this->logger->info('studio.image_analysis.started', [
                'studio_plan_id' => $analysis->studio_plan_id,
                'attempt_id' => $attemptId,
                'status' => $analysis->status->value,
            ]);
        }

        return $analysis;
    }
}
