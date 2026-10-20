<?php

namespace App\Jobs\Studio;

use App\Actions\Studio\CompleteStudioDiscussionGenerationAction;
use App\Actions\Studio\FailStudioDiscussionGenerationAction;
use App\Actions\Studio\StartStudioDiscussionGenerationAction;
use App\Enums\StudioDiscussionGenerationError;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Support\StudioAi\StudioDiscussionContext;
use App\Support\StudioAi\StudioDiscussionGenerationFailedException;
use App\Support\StudioAi\StudioDiscussionGenerator;
use App\Support\StudioAi\StudioDiscussionPrompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Writes one discussion generation: one request to OpenAI, at most, through
 * StudioDiscussionGenerator.
 *
 * It carries only the generation's id and attempt id — no Persona, no
 * analysis, no discussion and no key — and its timings nest as photo
 * analysis's do: the provider's 45 s inside this job's 75 s, inside the Redis
 * queue's 90 s retry_after. Exactly once, at most: the job claims its
 * generation before anything else (StartStudioDiscussionGenerationAction),
 * and only a queued generation with that attempt id can be claimed, so a job
 * delivered twice sends nothing. It is never retried: a retry may be a second
 * paid call, and a worker that died after sending leaves the generation to be
 * asked for again only by an administrator.
 *
 * Once claimed it reads the plan as it is now and sends nothing unless the
 * plan is still a Draft whose post, photo, analysis, discussion and Personas
 * are the ones the generation was asked for (its hashes), and writing
 * discussions is still configured; then it sends the stored analysis — never
 * the photo. No transaction is open while it waits for the provider. What
 * comes back is kept through CompleteStudioDiscussionGenerationAction, which
 * checks the plan again; a failure ends the generation with its reason
 * (FailStudioDiscussionGenerationAction).
 */
final class GenerateStudioDiscussionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $generationId,
        public readonly string $attemptId,
    ) {}

    public function handle(
        StartStudioDiscussionGenerationAction $start,
        StudioDiscussionGenerator $generator,
        CompleteStudioDiscussionGenerationAction $complete,
        FailStudioDiscussionGenerationAction $fail,
    ): void {
        $generation = $start->handle($this->generationId, $this->attemptId);

        if ($generation === null) {
            return;
        }

        $plan = StudioPlan::query()->find($generation->studio_plan_id);

        // Gone with its plan since the claim: nothing is left to fail.
        if ($plan === null) {
            return;
        }

        $context = $plan->isDraft()
            ? StudioDiscussionContext::of($plan, StudioPlanImageAnalysis::query()->where('studio_plan_id', $plan->id)->first(), $generation->persona_user_ids)
            : null;
        $prompt = $context instanceof StudioDiscussionContext ? StudioDiscussionPrompt::for($generation, $context, $plan) : null;

        $refusal = match (true) {
            ! $plan->isDraft() => StudioDiscussionGenerationError::PlanClosed,
            ! $context instanceof StudioDiscussionContext, $prompt === null,
            ! $generation->isFor($context->imageAssetId, $context->analysisHash, $context->discussionHash, $context->personasHash) => StudioDiscussionGenerationError::StaleContext,
            ! $generator->isConfigured() => StudioDiscussionGenerationError::NotConfigured,
            default => null,
        };

        if ($refusal !== null) {
            $fail->handle($this->generationId, $this->attemptId, $refusal);

            return;
        }

        try {
            $result = $generator->generate($prompt);
        } catch (StudioDiscussionGenerationFailedException $exception) {
            $fail->handle($this->generationId, $this->attemptId, $exception->error, $exception->call);

            return;
        }

        $complete->handle($this->generationId, $this->attemptId, $result);
    }

    /**
     * The job ended without finishing — an exception, a timeout: its
     * generation fails, for a fixed reason, so nobody waits for it. Nothing the
     * exception said is kept.
     */
    public function failed(?Throwable $exception = null): void
    {
        app(FailStudioDiscussionGenerationAction::class)->handle($this->generationId, $this->attemptId, StudioDiscussionGenerationError::UnexpectedFailure);
    }
}
