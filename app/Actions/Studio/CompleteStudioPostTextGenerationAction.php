<?php

namespace App\Actions\Studio;

use App\Enums\StudioPostTextGenerationError;
use App\Enums\StudioPostTextGenerationStatus;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\StudioPlanTextGeneration;
use App\Support\Observability\DomainLogger;
use App\Support\StudioAi\StudioPostTextContext;
use App\Support\StudioAi\StudioPostTextGenerationResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the texts a running batch came back with — when they still fit the
 * plan.
 *
 * In one transaction, in the project's locking order: the plan, its photo
 * analysis, then the batch. The texts are kept only when the plan is still
 * there and still a Draft, its photo is still the one the batch was written
 * for and its analysis still the one it was written from (the hash), and
 * the batch is still running. A photo or an analysis changed meanwhile, or a
 * plan past its Draft, ends the batch with the reason and drops the texts; a
 * plan deleted, or a batch already ended, leaves everything as it is. Each
 * drop is logged as discarded, with what the call cost.
 *
 * The plan itself is never written — not a field, not its version, and
 * never its final title or description — so texts arriving while an
 * administrator types are no conflict with their save.
 */
final class CompleteStudioPostTextGenerationAction
{
    public function __construct(private readonly DomainLogger $logger) {}

    /** @return bool whether the texts were kept */
    public function handle(int $generationId, string $attemptId, StudioPostTextGenerationResult $result): bool
    {
        $planId = StudioPlanTextGeneration::query()->whereKey($generationId)->value('studio_plan_id');

        /** @var array{0: string|null, 1: StudioPostTextGenerationError|null} $outcome */
        $outcome = $planId === null ? ['plan_gone', null] : DB::transaction(function () use ($planId, $generationId, $attemptId, $result): array {
            $plan = StudioPlan::query()->whereKey($planId)->lockForUpdate()->first();
            $analysis = StudioPlanImageAnalysis::query()->where('studio_plan_id', $planId)->lockForUpdate()->first();
            $generation = StudioPlanTextGeneration::query()->whereKey($generationId)->lockForUpdate()->first();

            if ($plan === null || $generation === null) {
                return ['plan_gone', null];
            }

            if ($generation->attempt_id !== $attemptId || $generation->status !== StudioPostTextGenerationStatus::Running) {
                return ['superseded', null];
            }

            $context = StudioPostTextContext::of($plan->image_asset_id, $analysis);

            $error = match (true) {
                ! $plan->isDraft() => StudioPostTextGenerationError::PlanClosed,
                $context === null || ! $generation->isFor($context->imageAssetId, $context->analysisHash) => StudioPostTextGenerationError::StaleAnalysis,
                default => null,
            };

            $call = [
                'model' => mb_substr($result->call->model, 0, 128),
                'request_id' => $result->call->requestId !== null ? mb_substr($result->call->requestId, 0, 255) : null,
                'input_tokens' => $result->call->inputTokens,
                'output_tokens' => $result->call->outputTokens,
                'total_tokens' => $result->call->totalTokens,
                'latency_ms' => $result->call->latencyMs,
                'finished_at' => CarbonImmutable::now(),
            ];

            if ($error !== null) {
                $generation->forceFill(['status' => StudioPostTextGenerationStatus::Failed, 'error_code' => $error, ...$call])->save();

                return [$error === StudioPostTextGenerationError::PlanClosed ? 'plan_closed' : 'analysis_changed', $error];
            }

            $generation->forceFill([
                'status' => StudioPostTextGenerationStatus::Completed,
                'error_code' => null,
                'titles' => $result->suggestions->titles,
                'descriptions' => $result->suggestions->descriptions,
                ...$call,
            ])->save();

            return [null, null];
        });

        [$dropped, $error] = $outcome;

        $context = ['studio_plan_id' => $planId, 'attempt_id' => $attemptId, ...$result->call->logContext()];

        $dropped === null
            ? $this->logger->info('studio.post_text_generation.completed', [...$context, 'status' => StudioPostTextGenerationStatus::Completed->value])
            : $this->logger->info('studio.post_text_generation.discarded', [...$context, 'reason' => $dropped, 'error_code' => $error?->value]);

        return $dropped === null;
    }
}
