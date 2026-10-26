<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\LocksStudioPlanDraft;
use App\Enums\StudioPostTextGenerationError;
use App\Enums\StudioPostTextGenerationStatus;
use App\Enums\StudioPostTextTone;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Exceptions\Studio\CannotRequestStudioPostTextGenerationException;
use App\Jobs\Studio\GenerateStudioPostTextsJob;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\StudioPlanTextGeneration;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\StudioAi\StudioPostTextContext;
use App\Support\StudioAi\StudioPostTextGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * An administrator asks for a batch of post text suggestions — Generate
 * suggestions, Generate 10 more or Try again on the editor's Post step — in
 * a tone, and in the voice of the post's author. Nothing is sent from here:
 * a batch is queued, and GenerateStudioPostTextsJob makes the one request
 * after the commit.
 *
 * In one transaction, in the project's locking order: the administrator's
 * row (LocksStudioPlanDraft) — who may still manage plans — then the plan,
 * still there and still a Draft, whose photo and author must be the ones
 * the editor showed (`$imageAssetId`, `$authorUserId`) — the voice the
 * texts are written in is the saved author's, so the editor saves a new one
 * first — then its photo analysis, which must have finished with a valid
 * description of that photo, then the plan's batches on their way. The plan is not changed: its version stays, so asking for
 * texts never stands in the way of a save.
 *
 * A paid request is never asked for twice by accident: while a batch of the
 * plan is waiting or running, asking again — a double click, or a second
 * administrator — starts nothing and returns that batch. One that has been
 * on its way for longer than any live one could (isStale()) is ended as
 * lost, and the new one takes its place.
 *
 * Each batch is a row of its own, so a new one never takes earlier texts
 * away. It records what it is written from — the photo, and the analysis
 * with the author's voice in one hash (StudioPostTextContext::sourceHash())
 * — so it is told apart once any of them changes.
 */
final class RequestStudioPostTextGenerationAction
{
    use LocksStudioPlanDraft;

    public function __construct(
        private readonly StudioPostTextGenerator $generator,
        private readonly FailStudioPostTextGenerationAction $fail,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  int  $imageAssetId  the photo the editor showed
     * @param  int|null  $authorUserId  the author the editor showed, or none
     * @return StudioPlanTextGeneration the batch queued now, or the one already on its way
     *
     * @throws CannotManageStudioPlanException when the administrator may not, or the plan is gone or past its Draft
     * @throws CannotRequestStudioPostTextGenerationException when writing texts is not configured, the photo has no finished analysis, or the photo or the author changed
     */
    public function handle(User $admin, StudioPlan $plan, int $imageAssetId, ?int $authorUserId, StudioPostTextTone $tone): StudioPlanTextGeneration
    {
        if (! $admin->can('manage-studio-plans')) {
            throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
        }

        if (! $this->generator->isConfigured()) {
            throw CannotRequestStudioPostTextGenerationException::becauseNotConfigured();
        }

        $now = CarbonImmutable::now();

        /** @var array{0: StudioPlanTextGeneration, 1: bool, 2: list<array{0: int, 1: string}>} $outcome */
        $outcome = DB::transaction(function () use ($admin, $plan, $imageAssetId, $authorUserId, $tone, $now): array {
            $this->lockAdministratorAndAuthor($admin);

            $locked = StudioPlan::query()->whereKey($plan->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw CannotManageStudioPlanException::becausePlanIsGone();
            }

            if (! $locked->isDraft()) {
                throw CannotManageStudioPlanException::becausePlanIsNotDraft();
            }

            if ($locked->image_asset_id === null) {
                throw CannotRequestStudioPostTextGenerationException::becauseAnalysisIsMissing();
            }

            if ($locked->image_asset_id !== $imageAssetId) {
                throw CannotRequestStudioPostTextGenerationException::becauseImageChanged();
            }

            if ($locked->author_user_id !== $authorUserId) {
                throw CannotRequestStudioPostTextGenerationException::becauseAuthorChanged();
            }

            $analysis = StudioPlanImageAnalysis::query()->where('studio_plan_id', $locked->id)->lockForUpdate()->first();
            $context = StudioPostTextContext::ofPlan($locked, $analysis);

            if ($context === null || ! $context->complete) {
                throw CannotRequestStudioPostTextGenerationException::becauseAnalysisIsMissing();
            }

            $onTheirWay = StudioPlanTextGeneration::query()
                ->where('studio_plan_id', $locked->id)
                ->whereIn('status', [StudioPostTextGenerationStatus::Queued->value, StudioPostTextGenerationStatus::Running->value])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            $underway = $onTheirWay->first(fn (StudioPlanTextGeneration $generation): bool => $generation->isUnderway($now));

            if ($underway !== null) {
                return [$underway, false, []];
            }

            // Lost on their way: ended, so the new batch is the plan's only one.
            $lost = [];

            foreach ($onTheirWay as $generation) {
                $generation->forceFill([
                    'status' => StudioPostTextGenerationStatus::Failed,
                    'error_code' => StudioPostTextGenerationError::UnexpectedFailure,
                ])->save();
                $lost[] = [$generation->studio_plan_id, $generation->attempt_id];
            }

            $generation = new StudioPlanTextGeneration;
            $generation->forceFill([
                'studio_plan_id' => $locked->id,
                'source_image_asset_id' => $context->imageAssetId,
                'source_analysis_hash' => $context->sourceHash(),
                'tone' => $tone,
                'status' => StudioPostTextGenerationStatus::Queued,
                'attempt_id' => (string) Str::uuid(),
                'requested_at' => $now,
            ])->save();

            return [$generation, true, $lost];
        });

        [$generation, $queued, $lost] = $outcome;

        foreach ($lost as [$planId, $attemptId]) {
            $this->logger->warning('studio.post_text_generation.failed', [
                'studio_plan_id' => $planId,
                'attempt_id' => $attemptId,
                'status' => StudioPostTextGenerationStatus::Failed->value,
                'error_code' => StudioPostTextGenerationError::UnexpectedFailure->value,
                'reason' => 'lost',
            ]);
        }

        if (! $queued) {
            return $generation;
        }

        $this->logger->info('studio.post_text_generation.requested', [
            'studio_plan_id' => $generation->studio_plan_id,
            'attempt_id' => $generation->attempt_id,
            'tone' => $generation->tone->value,
            'status' => $generation->status->value,
        ]);

        try {
            GenerateStudioPostTextsJob::dispatch($generation->id, $generation->attempt_id)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);

            // The queue could not take the job — or, run inline, it failed
            // and has already said so. Either way the batch ends here, and
            // asking again is the administrator's choice.
            $this->fail->handle($generation->id, $generation->attempt_id, StudioPostTextGenerationError::UnexpectedFailure);
        }

        return $generation->refresh();
    }
}
