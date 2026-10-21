<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\LocksStudioPlanDraft;
use App\Data\Studio\SavedStudioDiscussion;
use App\Data\Studio\StudioDiscussionData;
use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationStatus;
use App\Exceptions\Studio\CannotApplyStudioDiscussionGenerationException;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Studio\StudioDiscussionValidationRules;
use App\Support\StudioAi\StudioDiscussionContext;
use App\Support\StudioAi\StudioDiscussionProposal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * An administrator takes a discussion generation's proposal into the plan
 * (docs/architecture/studio-plans.md, "Discussion generation") — the trusted
 * step the editor's Discussion screen will offer:
 *
 * - a whole discussion takes the place of the plan's, every comment of it
 *   new, given its id by the save;
 * - a part written again changes only the texts of its own comments — no id,
 *   author, parent, place or time, and no other comment.
 *
 * Only a proposal written from the context the plan still has: its post, its
 * photo and analysis, its discussion and its Personas as they were when it was
 * asked for (the hashes the generation keeps). A discussion edited by hand
 * since, a comment deleted, a Persona changed — anything — and the proposal
 * can no longer be taken in, so nothing saved is ever written over without
 * being seen. Each proposal is taken in once.
 *
 * The discussion is written through SaveStudioDiscussionTreeAction, the one
 * door every discussion goes through, its checks and all; the save and
 * `applied_at` commit together, or not at all. The locks are taken in the
 * project's order — the `users` rows of the administrator and of every
 * Persona the check or the save depends on, in ascending id order: those the
 * proposal was written for, even when none of their comments made it in, the
 * authors of the discussion it was written from, and the authors of the one
 * it makes; then the plan at the version the administrator saw, its photo
 * analysis, then the generation. None is locked after the plan: a Persona
 * found only once the plan is locked refuses the proposal instead. So a hand
 * save, another administrator taking the same proposal in, a new date for the
 * post, or an edit of any of those Personas takes turns with it, the later
 * one refused or waiting, and none goes unnoticed between the check and the
 * commit.
 */
final class ApplyStudioDiscussionGenerationAction
{
    use LocksStudioPlanDraft;

    public function __construct(
        private readonly SaveStudioDiscussionTreeAction $save,
        private readonly StudioDiscussionValidationRules $rules,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * Whether the plan, as it is now, would take this proposal in: the
     * checks handle() makes, read only — a Draft, a proposal completed and
     * not yet taken in, written from the context the plan still has, and a
     * discussion the discussion's rules take, its Personas as they are now.
     * What decides is still handle(), under its locks: this says only which
     * finished proposal is worth taking in rather than asking for another.
     */
    public function fits(StudioPlan $plan, StudioPlanDiscussionGeneration $generation): bool
    {
        $proposal = StudioDiscussionProposal::fromStored($generation->mode, $generation->result);

        if ($generation->studio_plan_id !== $plan->id || $generation->status !== StudioDiscussionGenerationStatus::Completed
            || $generation->applied_at !== null || $proposal === null || ! $plan->isDraft()) {
            return false;
        }

        $analysis = StudioPlanImageAnalysis::query()->where('studio_plan_id', $plan->id)->first();
        $context = StudioDiscussionContext::of($plan, $analysis, $generation->persona_user_ids);

        if (! $context instanceof StudioDiscussionContext
            || ! $generation->isFor($context->imageAssetId, $context->analysisHash, $context->discussionHash, $context->personasHash)) {
            return false;
        }

        try {
            $discussion = $this->rules->validated($proposal->applyTo(StudioDiscussionProposal::current($context)));
            $this->rules->validateAgainst($discussion, $plan, User::query()->whereKey($discussion->personaIds())->get()->keyBy('id'));
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * @param  int  $expectedLockVersion  the plan's lock_version as the administrator saw it
     * @return SavedStudioDiscussion the plan's discussion as it now is, and its new version
     *
     * @throws CannotManageStudioPlanException when the administrator may not, or the plan is gone, past its Draft or changed meanwhile
     * @throws CannotApplyStudioDiscussionGenerationException when the proposal is not the plan's, has none, was taken in already, or no longer fits
     * @throws ValidationException when the discussion it makes breaks the discussion's own rules now
     */
    public function handle(User $admin, StudioPlan $plan, int $generationId, int $expectedLockVersion): SavedStudioDiscussion
    {
        try {
            if (! $admin->can('manage-studio-plans')) {
                throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
            }

            // Read before any lock, so every row the check and the save depend
            // on is locked before the plan's; asked again once they are.
            $generation = StudioPlanDiscussionGeneration::query()->where('studio_plan_id', $plan->getKey())->whereKey($generationId)->first();

            if ($generation === null) {
                throw new CannotApplyStudioDiscussionGenerationException(StudioDiscussionGenerationError::NotCompleted);
            }

            $proposal = StudioDiscussionProposal::fromStored($generation->mode, $generation->result);
            $current = StudioDiscussionData::of($plan);
            $personaIds = self::dependedOn($generation->persona_user_ids, $current, $proposal?->applyTo($current));

            [$saved, $applied] = DB::transaction(function () use ($admin, $plan, $generationId, $expectedLockVersion, $personaIds): array {
                $users = $this->lockUsersInOrder((int) $admin->getKey(), ...$personaIds);
                $lockedAdmin = $users->get($admin->getKey());

                if ($lockedAdmin === null || ! $lockedAdmin->can('manage-studio-plans')) {
                    throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
                }

                $locked = $this->currentDraft((int) $plan->getKey(), $expectedLockVersion);
                $analysis = StudioPlanImageAnalysis::query()->where('studio_plan_id', $locked->id)->lockForUpdate()->first();
                $generation = StudioPlanDiscussionGeneration::query()->where('studio_plan_id', $locked->id)->whereKey($generationId)->lockForUpdate()->first();
                $proposal = $generation !== null ? StudioDiscussionProposal::fromStored($generation->mode, $generation->result) : null;

                $refusal = match (true) {
                    $generation === null, $generation->status !== StudioDiscussionGenerationStatus::Completed, $proposal === null => StudioDiscussionGenerationError::NotCompleted,
                    $generation->applied_at !== null => StudioDiscussionGenerationError::AlreadyApplied,
                    default => null,
                };

                if ($refusal !== null) {
                    throw new CannotApplyStudioDiscussionGenerationException($refusal);
                }

                $context = StudioDiscussionContext::of($locked, $analysis, $generation->persona_user_ids);

                if (! $context instanceof StudioDiscussionContext
                    || ! $generation->isFor($context->imageAssetId, $context->analysisHash, $context->discussionHash, $context->personasHash)) {
                    throw new CannotApplyStudioDiscussionGenerationException(StudioDiscussionGenerationError::StaleContext);
                }

                $current = StudioDiscussionProposal::current($context);
                $discussion = $proposal->applyTo($current);

                // Every user the check and the save depend on — the Personas
                // the proposal was written for, its authors, and the authors of
                // the discussion it was written from — is one locked above:
                // none is locked after the plan. More than were read before the
                // locks means the plan changed meanwhile.
                if (array_diff(self::dependedOn($generation->persona_user_ids, $current, $discussion), $personaIds) !== []) {
                    throw new CannotApplyStudioDiscussionGenerationException(StudioDiscussionGenerationError::StaleContext);
                }

                $saved = $this->save->handle($lockedAdmin, $plan, $discussion, $expectedLockVersion);

                if (! $generation->forceFill(['applied_at' => CarbonImmutable::now()])->save()) {
                    throw new RuntimeException('The discussion generation was not saved as applied.');
                }

                return [$saved, $generation];
            });
        } catch (CannotManageStudioPlanException|CannotApplyStudioDiscussionGenerationException|ValidationException $refusal) {
            $this->logger->warning('studio.discussion_generation.rejected', [
                'studio_plan_id' => $plan->getKey(),
                'generation_id' => $generationId,
                'admin_id' => $admin->getKey(),
                'error_code' => self::refusalCode($refusal),
            ]);

            throw $refusal;
        }

        $this->logger->info('studio.discussion_generation.applied', [
            'studio_plan_id' => $plan->getKey(),
            'generation_id' => $applied->id,
            'admin_id' => $admin->getKey(),
            'mode' => $applied->mode->value,
            'target_node_id' => $applied->targetNodeId(),
            'nodes' => StudioDiscussionProposal::fromStored($applied->mode, $applied->result)?->count(),
            'lock_version' => $saved->lockVersion,
        ]);

        return $saved;
    }

    /**
     * The Personas taking a proposal in depends on: those it was written for,
     * whose profiles its context's hash holds even when none of their comments
     * made it in; the authors of the discussion it was written from, whose
     * names the hash holds; and the authors of the discussion it makes.
     *
     * @param  list<int>  $participants
     * @return list<int> ascending
     */
    private static function dependedOn(array $participants, StudioDiscussionData $current, ?StudioDiscussionData $proposed): array
    {
        $ids = array_values(array_unique([...$participants, ...$current->personaIds(), ...($proposed?->personaIds() ?? [])]));
        sort($ids);

        return $ids;
    }

    private static function refusalCode(Throwable $refusal): string
    {
        return match (true) {
            $refusal instanceof CannotApplyStudioDiscussionGenerationException => $refusal->error->value,
            $refusal instanceof CannotManageStudioPlanException && $refusal->isStale() => StudioDiscussionGenerationError::StaleVersion->value,
            $refusal instanceof CannotManageStudioPlanException && $refusal->isNotDraft() => StudioDiscussionGenerationError::PlanClosed->value,
            $refusal instanceof CannotManageStudioPlanException && $refusal->isGone() => 'plan_gone',
            $refusal instanceof CannotManageStudioPlanException => 'access_denied',
            default => 'invalid_discussion',
        };
    }
}
