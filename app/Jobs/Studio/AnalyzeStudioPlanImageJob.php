<?php

namespace App\Jobs\Studio;

use App\Actions\Studio\CompleteStudioImageAnalysisAction;
use App\Actions\Studio\FailStudioImageAnalysisAction;
use App\Actions\Studio\StartStudioImageAnalysisAction;
use App\Enums\StudioImageAnalysisError;
use App\Models\StudioPlan;
use App\Support\StudioAi\StudioAnalysisImageReader;
use App\Support\StudioAi\StudioImageAnalysisFailedException;
use App\Support\StudioAi\StudioImageAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Analyses one attempt's photo: one request to OpenAI, at most, through
 * StudioImageAnalyzer.
 *
 * It carries only the analysis row's id and the attempt id — no model, no
 * image and no key. The timings nest, as Translation AI's jobs' do:
 *
 *   provider request timeout   45 s
 *   this job's timeout         75 s   its own, ahead of the worker's
 *   Redis queue retry_after    90 s
 *   queue worker timeout      120 s
 *
 * so the job is stopped well before Redis would hand it to another worker as
 * if it had been lost. Exactly once, at most: the job claims its attempt
 * before anything else (StartStudioImageAnalysisAction), and only a queued
 * attempt with that id can be claimed, so a job delivered twice — or one of
 * an attempt replaced since — sends nothing. It is never retried: a retry
 * may be a second paid call, and trying again is the administrator's choice.
 *
 * Once claimed it checks, on the plan as it is now, that the plan is still a
 * Draft whose photo is the one the attempt is for, and that analysis is still
 * configured; then it reads the photo and sends it. No transaction is open
 * while it waits for the provider. What comes back is kept through
 * CompleteStudioImageAnalysisAction, which checks the plan again; a failure
 * ends the attempt with its reason (FailStudioImageAnalysisAction).
 */
final class AnalyzeStudioPlanImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $analysisId,
        public readonly string $attemptId,
    ) {}

    public function handle(
        StartStudioImageAnalysisAction $start,
        StudioAnalysisImageReader $reader,
        StudioImageAnalyzer $analyzer,
        CompleteStudioImageAnalysisAction $complete,
        FailStudioImageAnalysisAction $fail,
    ): void {
        $analysis = $start->handle($this->analysisId, $this->attemptId);

        if ($analysis === null) {
            return;
        }

        $plan = StudioPlan::query()->find($analysis->studio_plan_id);

        // Gone with its plan since the claim: nothing is left to fail.
        if ($plan === null) {
            return;
        }

        $refusal = match (true) {
            ! $plan->isDraft() => StudioImageAnalysisError::PlanClosed,
            ! $analysis->isFor($plan->image_asset_id) => StudioImageAnalysisError::StaleImage,
            ! $analyzer->isConfigured() => StudioImageAnalysisError::NotConfigured,
            default => null,
        };

        if ($refusal !== null) {
            $fail->handle($this->analysisId, $this->attemptId, $refusal);

            return;
        }

        try {
            $result = $analyzer->analyze($reader->read($analysis->source_image_asset_id));
        } catch (StudioImageAnalysisFailedException $exception) {
            $fail->handle($this->analysisId, $this->attemptId, $exception->error, $exception->call);

            return;
        }

        $complete->handle($this->analysisId, $this->attemptId, $result);
    }

    /**
     * The job ended without finishing — an exception, a timeout: its attempt
     * fails, for a fixed reason, so the editor never waits for it. Nothing the
     * exception said is kept.
     */
    public function failed(?Throwable $exception = null): void
    {
        app(FailStudioImageAnalysisAction::class)->handle($this->analysisId, $this->attemptId, StudioImageAnalysisError::UnexpectedFailure);
    }
}
