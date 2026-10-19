<?php

namespace App\Jobs\Studio;

use App\Actions\Studio\CompleteStudioPostTextGenerationAction;
use App\Actions\Studio\FailStudioPostTextGenerationAction;
use App\Actions\Studio\StartStudioPostTextGenerationAction;
use App\Enums\StudioPostTextGenerationError;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Support\StudioAi\StudioPostTextContext;
use App\Support\StudioAi\StudioPostTextGenerationFailedException;
use App\Support\StudioAi\StudioPostTextGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Writes one batch of post text suggestions: one request to OpenAI, at most,
 * through StudioPostTextGenerator.
 *
 * It carries only the batch's id and attempt id — no texts, no analysis and
 * no key — and its timings nest as photo analysis's do: the provider's 45 s
 * inside this job's 75 s, inside the Redis queue's 90 s retry_after. Exactly
 * once, at most: the job claims its batch before anything else
 * (StartStudioPostTextGenerationAction), and only a queued batch with that
 * attempt id can be claimed, so a job delivered twice sends nothing. It is
 * never retried: a retry may be a second paid call, and trying again is the
 * administrator's choice.
 *
 * Once claimed it checks, on the plan as it is now, that the plan is still a
 * Draft whose photo and analysis are the ones the batch is for, and that
 * writing texts is still configured; then it sends the stored analysis —
 * never the photo. No transaction is open while it waits for the provider.
 * What comes back is kept through CompleteStudioPostTextGenerationAction,
 * which checks the plan again; a failure ends the batch with its reason
 * (FailStudioPostTextGenerationAction).
 */
final class GenerateStudioPostTextsJob implements ShouldQueue
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
        StartStudioPostTextGenerationAction $start,
        StudioPostTextGenerator $generator,
        CompleteStudioPostTextGenerationAction $complete,
        FailStudioPostTextGenerationAction $fail,
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

        $context = StudioPostTextContext::of(
            $plan->image_asset_id,
            StudioPlanImageAnalysis::query()->where('studio_plan_id', $plan->id)->first(),
        );

        $refusal = match (true) {
            ! $plan->isDraft() => StudioPostTextGenerationError::PlanClosed,
            $context === null || ! $generation->isFor($context->imageAssetId, $context->analysisHash) => StudioPostTextGenerationError::StaleAnalysis,
            ! $generator->isConfigured() => StudioPostTextGenerationError::NotConfigured,
            default => null,
        };

        if ($refusal !== null) {
            $fail->handle($this->generationId, $this->attemptId, $refusal);

            return;
        }

        try {
            $result = $generator->generate($context->description, $generation->tone);
        } catch (StudioPostTextGenerationFailedException $exception) {
            $fail->handle($this->generationId, $this->attemptId, $exception->error, $exception->call);

            return;
        }

        $complete->handle($this->generationId, $this->attemptId, $result);
    }

    /**
     * The job ended without finishing — an exception, a timeout: its batch
     * fails, for a fixed reason, so the editor never waits for it. Nothing
     * the exception said is kept.
     */
    public function failed(?Throwable $exception = null): void
    {
        app(FailStudioPostTextGenerationAction::class)->handle($this->generationId, $this->attemptId, StudioPostTextGenerationError::UnexpectedFailure);
    }
}
