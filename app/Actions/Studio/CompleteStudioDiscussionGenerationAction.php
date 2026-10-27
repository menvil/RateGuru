<?php

namespace App\Actions\Studio;

use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationStatus;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\StudioPlanImageAnalysis;
use App\Support\Observability\DomainLogger;
use App\Support\Studio\StudioDiscussionValidationRules;
use App\Support\StudioAi\StudioDiscussionContext;
use App\Support\StudioAi\StudioDiscussionGenerationResult;
use App\Support\StudioAi\StudioDiscussionPrompt;
use App\Support\StudioAi\StudioDiscussionProposal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Keeps what a running discussion generation came back with as its proposal
 * — when the answer is one, and the plan is still what it was written from.
 * Completed says only that: a proposal was written. The plan's discussion is
 * never touched here; an administrator takes a proposal in
 * (ApplyStudioDiscussionGenerationAction).
 *
 * In one transaction, in the project's locking order: the plan, its photo
 * analysis, then the generation. A plan past its Draft ends it as
 * plan_closed; a plan whose post, photo, analysis, discussion or Personas
 * changed since it was asked for — the hashes it keeps — ends it as
 * stale_context, so nothing out of date is ever kept as a proposal; an answer
 * that is not a valid proposal for the plan as it is ends it with the reason
 * (StudioDiscussionProposal). A plan deleted, or a generation already ended,
 * leaves everything as it is. Each drop is logged, with what the call cost.
 */
final class CompleteStudioDiscussionGenerationAction
{
    public function __construct(
        private readonly StudioDiscussionValidationRules $rules,
        private readonly DomainLogger $logger,
    ) {}

    /** @return bool whether the proposal was kept */
    public function handle(int $generationId, string $attemptId, StudioDiscussionGenerationResult $result): bool
    {
        $planId = StudioPlanDiscussionGeneration::query()->whereKey($generationId)->value('studio_plan_id');

        /** @var array{0: string|null, 1: StudioDiscussionGenerationError|null, 2: StudioPlanDiscussionGeneration|null} $outcome */
        $outcome = $planId === null ? ['plan_gone', null, null] : DB::transaction(function () use ($planId, $generationId, $attemptId, $result): array {
            $plan = StudioPlan::query()->whereKey($planId)->lockForUpdate()->first();
            $analysis = StudioPlanImageAnalysis::query()->where('studio_plan_id', $planId)->lockForUpdate()->first();
            $generation = StudioPlanDiscussionGeneration::query()->whereKey($generationId)->lockForUpdate()->first();

            if ($plan === null || $generation === null) {
                return ['plan_gone', null, $generation];
            }

            if ($generation->attempt_id !== $attemptId || $generation->status !== StudioDiscussionGenerationStatus::Running) {
                return ['superseded', null, $generation];
            }

            $context = $plan->isDraft() ? StudioDiscussionContext::of($plan, $analysis, $generation->persona_user_ids) : null;
            $prompt = $context instanceof StudioDiscussionContext ? StudioDiscussionPrompt::for($generation, $context, $plan) : null;

            $proposal = match (true) {
                ! $plan->isDraft() => StudioDiscussionGenerationError::PlanClosed,
                ! $context instanceof StudioDiscussionContext, $prompt === null,
                ! $generation->isFor($context->imageAssetId, $context->analysisHash, $context->discussionHash, $context->personasHash) => StudioDiscussionGenerationError::StaleContext,
                default => StudioDiscussionProposal::from($prompt, $result->answer, $this->rules),
            };

            $call = [...$result->call->attributes(), 'finished_at' => CarbonImmutable::now()];

            if ($proposal instanceof StudioDiscussionGenerationError) {
                self::saved($generation->forceFill(['status' => StudioDiscussionGenerationStatus::Failed, 'error_code' => $proposal, ...$call]));

                return [$proposal->value, $proposal, $generation];
            }

            self::saved($generation->forceFill([
                'status' => StudioDiscussionGenerationStatus::Completed,
                'error_code' => null,
                'result' => $proposal->toArray(),
                ...$call,
            ]));

            return [null, null, $generation];
        });

        [$dropped, $error, $generation] = $outcome;

        $context = [
            'studio_plan_id' => $planId,
            'generation_id' => $generationId,
            'attempt_id' => $attemptId,
            'mode' => $generation?->mode->value,
            'target_node_id' => $generation?->targetNodeId(),
            ...$result->call->logContext(),
        ];

        match (true) {
            $dropped === null => $this->logger->info('studio.discussion_generation.completed', [
                ...$context,
                'status' => StudioDiscussionGenerationStatus::Completed->value,
                'nodes' => StudioDiscussionProposal::fromStored($generation->mode, $generation->result)?->count(),
            ]),
            $error !== null => $this->logger->warning('studio.discussion_generation.failed', [
                ...$context,
                'status' => StudioDiscussionGenerationStatus::Failed->value,
                'error_code' => $error->value,
            ]),
            default => $this->logger->info('studio.discussion_generation.discarded', [...$context, 'reason' => $dropped]),
        };

        return $dropped === null;
    }

    /**
     * A save a model event cancels returns false rather than throwing; here
     * it fails the whole completion, as an exception would.
     */
    private static function saved(StudioPlanDiscussionGeneration $generation): void
    {
        if (! $generation->save()) {
            throw new RuntimeException('The discussion generation was not saved.');
        }
    }
}
