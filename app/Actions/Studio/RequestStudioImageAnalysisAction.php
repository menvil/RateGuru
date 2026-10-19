<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\LocksStudioPlanDraft;
use App\Enums\StudioImageAnalysisError;
use App\Enums\StudioImageAnalysisStatus;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Exceptions\Studio\CannotRequestStudioImageAnalysisException;
use App\Jobs\Studio\AnalyzeStudioPlanImageJob;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\StudioAi\StudioImageAnalyzer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * An administrator asks for a Draft's saved photo to be analysed — Analyze
 * photo, Re-run analysis or Try again in the editor. Nothing is sent from
 * here: an attempt is queued, and AnalyzeStudioPlanImageJob makes the one
 * request after the commit.
 *
 * In one transaction, in the project's locking order: the administrator's
 * row (LocksStudioPlanDraft) — who may still manage plans — then the plan,
 * still there and still a Draft, whose photo must be the one the editor
 * showed (`$imageAssetId`), then its analysis row. The plan is not changed:
 * its version stays, so an analysis never stands in the way of a save.
 *
 * A paid request is never asked for twice by accident. While an attempt for
 * the photo is on its way, asking again — a double click, or a second
 * administrator — starts nothing and returns it. Once the photo has a result
 * or a failed attempt, a new attempt starts only when the administrator asks
 * to replace that very attempt (`$replacing`, the attempt id the editor
 * showed): two administrators re-running the same result start one, and a
 * re-run of a result somebody else has already replaced starts none. An
 * attempt that has been waiting or running far longer than any live one
 * could (StudioPlanImageAnalysis::isStale()) may be replaced the same way.
 *
 * A new attempt for the same photo keeps its last result until a new one
 * replaces it; one for another photo clears it. The attempt gets a new id,
 * so a job of an earlier attempt finds nothing to claim.
 */
final class RequestStudioImageAnalysisAction
{
    use LocksStudioPlanDraft;

    public function __construct(
        private readonly StudioImageAnalyzer $analyzer,
        private readonly FailStudioImageAnalysisAction $fail,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  int  $imageAssetId  the photo the editor showed
     * @param  string|null  $replacing  the attempt the administrator asked to replace — the result or failure on screen; null for a photo not analysed yet
     * @return StudioPlanImageAnalysis the attempt queued now, or the one that stands
     *
     * @throws CannotManageStudioPlanException when the administrator may not, or the plan is gone or past its Draft
     * @throws CannotRequestStudioImageAnalysisException when analysis is not configured, the plan has no photo, or its photo changed
     */
    public function handle(User $admin, StudioPlan $plan, int $imageAssetId, ?string $replacing = null): StudioPlanImageAnalysis
    {
        if (! $admin->can('manage-studio-plans')) {
            throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
        }

        if (! $this->analyzer->isConfigured()) {
            throw CannotRequestStudioImageAnalysisException::becauseNotConfigured();
        }

        $now = CarbonImmutable::now();

        /** @var array{0: StudioPlanImageAnalysis, 1: bool} $outcome */
        $outcome = DB::transaction(function () use ($admin, $plan, $imageAssetId, $replacing, $now): array {
            $this->lockAdministratorAndAuthor($admin);

            $locked = StudioPlan::query()->whereKey($plan->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw CannotManageStudioPlanException::becausePlanIsGone();
            }

            if (! $locked->isDraft()) {
                throw CannotManageStudioPlanException::becausePlanIsNotDraft();
            }

            if ($locked->image_asset_id === null) {
                throw CannotRequestStudioImageAnalysisException::becausePlanHasNoImage();
            }

            if ($locked->image_asset_id !== $imageAssetId) {
                throw CannotRequestStudioImageAnalysisException::becauseImageChanged();
            }

            $analysis = StudioPlanImageAnalysis::query()->where('studio_plan_id', $locked->id)->lockForUpdate()->first();

            if ($analysis !== null && ! $this->startsAnew($analysis, $imageAssetId, $replacing, $now)) {
                return [$analysis, false];
            }

            $analysis ??= new StudioPlanImageAnalysis;
            $keepsResult = $analysis->exists && $analysis->isFor($imageAssetId);

            $analysis->forceFill([
                'studio_plan_id' => $locked->id,
                'source_image_asset_id' => $imageAssetId,
                'status' => StudioImageAnalysisStatus::Queued,
                'attempt_id' => (string) Str::uuid(),
                'error_code' => null,
                'requested_at' => $now,
                'started_at' => null,
                ...($keepsResult ? [] : [
                    'result' => null,
                    'model' => null,
                    'request_id' => null,
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'total_tokens' => null,
                    'latency_ms' => null,
                    'finished_at' => null,
                ]),
            ])->save();

            return [$analysis, true];
        });

        [$analysis, $queued] = $outcome;

        if (! $queued) {
            return $analysis;
        }

        $this->logger->info('studio.image_analysis.requested', [
            'studio_plan_id' => $analysis->studio_plan_id,
            'attempt_id' => $analysis->attempt_id,
            'status' => $analysis->status->value,
        ]);

        try {
            AnalyzeStudioPlanImageJob::dispatch($analysis->id, $analysis->attempt_id)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);

            // The queue could not take the job — or, run inline, it failed
            // and has already said so. Either way the attempt ends here, and
            // asking again is the administrator's choice.
            $this->fail->handle($analysis->id, $analysis->attempt_id, StudioImageAnalysisError::UnexpectedFailure);
        }

        return $analysis->refresh();
    }

    /**
     * Whether the row's photo is another one — it was replaced — or its
     * latest attempt has ended (or been lost) and is the one the
     * administrator asked to replace.
     */
    private function startsAnew(StudioPlanImageAnalysis $analysis, int $imageAssetId, ?string $replacing, CarbonImmutable $now): bool
    {
        if (! $analysis->isFor($imageAssetId)) {
            return true;
        }

        if ($analysis->isUnderway($now)) {
            return false;
        }

        return $replacing !== null && hash_equals($analysis->attempt_id, $replacing);
    }
}
