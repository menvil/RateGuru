<?php

namespace App\Actions\Studio;

use App\Enums\StudioImageAnalysisError;
use App\Enums\StudioImageAnalysisStatus;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Support\Observability\DomainLogger;
use App\Support\StudioAi\StudioImageAnalysisResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the description a running attempt came back with — when it still
 * describes the plan's photo.
 *
 * In one transaction, in the project's locking order: the plan, then its
 * analysis row. The result is kept only when the plan is still there and
 * still a Draft, its photo is still the one the attempt was for, and the
 * attempt is still the row's latest and still running. A photo replaced or
 * removed meanwhile, or a plan past its Draft, ends the attempt with the
 * reason and drops the result; a plan deleted, or an attempt replaced by a
 * newer one, leaves everything as it is. Each drop is logged as discarded,
 * with what the call cost.
 *
 * The plan itself is never written — not a field, not its version — so an
 * analysis finishing while an administrator types is no conflict with their
 * save.
 */
final class CompleteStudioImageAnalysisAction
{
    public function __construct(private readonly DomainLogger $logger) {}

    /** @return bool whether the result was kept */
    public function handle(int $analysisId, string $attemptId, StudioImageAnalysisResult $result): bool
    {
        $planId = StudioPlanImageAnalysis::query()->whereKey($analysisId)->value('studio_plan_id');

        /** @var array{0: string|null, 1: StudioImageAnalysisError|null} $outcome */
        $outcome = $planId === null ? ['plan_gone', null] : DB::transaction(function () use ($planId, $analysisId, $attemptId, $result): array {
            $plan = StudioPlan::query()->whereKey($planId)->lockForUpdate()->first();
            $analysis = StudioPlanImageAnalysis::query()->whereKey($analysisId)->lockForUpdate()->first();

            if ($plan === null || $analysis === null) {
                return ['plan_gone', null];
            }

            if ($analysis->attempt_id !== $attemptId || $analysis->status !== StudioImageAnalysisStatus::Running) {
                return ['superseded', null];
            }

            $error = match (true) {
                ! $plan->isDraft() => StudioImageAnalysisError::PlanClosed,
                ! $analysis->isFor($plan->image_asset_id) => StudioImageAnalysisError::StaleImage,
                default => null,
            };

            if ($error !== null) {
                $analysis->forceFill([
                    'status' => StudioImageAnalysisStatus::Failed,
                    'error_code' => $error,
                ])->save();

                return [$error === StudioImageAnalysisError::PlanClosed ? 'plan_closed' : 'image_changed', $error];
            }

            $analysis->forceFill([
                'status' => StudioImageAnalysisStatus::Completed,
                'error_code' => null,
                'result' => $result->description->toArray(),
                'model' => mb_substr($result->call->model, 0, 128),
                'request_id' => $result->call->requestId !== null ? mb_substr($result->call->requestId, 0, 255) : null,
                'input_tokens' => $result->call->inputTokens,
                'output_tokens' => $result->call->outputTokens,
                'total_tokens' => $result->call->totalTokens,
                'latency_ms' => $result->call->latencyMs,
                'finished_at' => CarbonImmutable::now(),
            ])->save();

            return [null, null];
        });

        [$dropped, $error] = $outcome;

        $context = ['studio_plan_id' => $planId, 'attempt_id' => $attemptId, ...$result->call->logContext()];

        $dropped === null
            ? $this->logger->info('studio.image_analysis.completed', [...$context, 'status' => StudioImageAnalysisStatus::Completed->value])
            : $this->logger->info('studio.image_analysis.discarded', [...$context, 'reason' => $dropped, 'error_code' => $error?->value]);

        return $dropped === null;
    }
}
