<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\LocksStudioPlanDraft;
use App\Data\Studio\StudioDiscussionGenerationData;
use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioDiscussionGenerationStatus;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Exceptions\Studio\CannotRequestStudioDiscussionGenerationException;
use App\Jobs\Studio\GenerateStudioDiscussionJob;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Studio\StudioDiscussionValidationRules;
use App\Support\StudioAi\StudioDiscussionContext;
use App\Support\StudioAi\StudioDiscussionGenerator;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * An administrator asks for a discussion to be written
 * (docs/architecture/studio-plans.md, "Discussion generation") — a whole one
 * by the Personas they chose, or one part of the plan's discussion again: a
 * comment, a reply, or a comment with its replies. Nothing is sent from here:
 * a generation is queued, and GenerateStudioDiscussionJob makes the one
 * request after the commit. Nothing of the plan is changed — not its
 * discussion, not its version — so asking never stands in the way of a save.
 *
 * In one transaction, in the project's locking order: the administrator's
 * and every Persona's `users` rows in ascending id order — the Personas
 * chosen, or the authors of the part written again, read before any lock —
 * then the plan, still a Draft at the version the editor was drawn from, then
 * its photo analysis, then its generations on their way. On the locked rows
 * everything is asked again: the administrator may still manage plans; every
 * Persona is still one and not deleted — a Limited, Shadowbanned or Banned
 * one may take part in a draft, as in a discussion saved by hand, and its
 * engagement and daily reply limit, which govern replies of its own, play no
 * part; the part is still in the plan's discussion at the level asked; and
 * the post has a finished analysis of its photo and a final title to write
 * from (StudioDiscussionContext). What it is written from is kept as hashes,
 * so a proposal is used only while the plan still has the same; a whole one
 * also keeps the fingerprint of the post's final title and description, so
 * the editor can say once a tree taken in was written for earlier texts.
 *
 * A paid request is never asked for twice by accident: while a generation of
 * the plan is waiting or running, asking again — a double click, or a second
 * administrator — starts nothing and returns that one. One that has been on
 * its way for longer than any live one could (isStale()) is ended as lost, and
 * the new one takes its place.
 */
final class RequestStudioDiscussionGenerationAction
{
    use LocksStudioPlanDraft;

    public function __construct(
        private readonly StudioDiscussionGenerator $generator,
        private readonly FailStudioDiscussionGenerationAction $fail,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @return StudioPlanDiscussionGeneration the generation queued now, or the one already on its way
     *
     * @throws CannotManageStudioPlanException when the administrator may not, or the plan is gone, past its Draft or changed meanwhile
     * @throws CannotRequestStudioDiscussionGenerationException when generation is not configured, or the post, the Personas or the part cannot be written from
     * @throws ValidationException keyed as the request's fields, for counts out of bounds
     */
    public function handle(User $admin, StudioPlan $plan, StudioDiscussionGenerationData $data): StudioPlanDiscussionGeneration
    {
        try {
            if (! $admin->can('manage-studio-plans')) {
                throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
            }

            if (! $this->generator->isConfigured()) {
                throw new CannotRequestStudioDiscussionGenerationException(StudioDiscussionGenerationError::NotConfigured);
            }

            $this->validate($data);

            // Read before any lock, so their rows are locked before the plan's.
            $personaIds = $this->participants($plan, $data);
            $now = CarbonImmutable::now();

            /** @var array{0: StudioPlanDiscussionGeneration, 1: bool, 2: list<array{0: int, 1: int, 2: string}>} $outcome */
            $outcome = DB::transaction(fn (): array => $this->queue($admin, $plan, $data, $personaIds, $now));
        } catch (CannotManageStudioPlanException|CannotRequestStudioDiscussionGenerationException|ValidationException $refusal) {
            $this->logger->warning('studio.discussion_generation.rejected', [
                'studio_plan_id' => $plan->getKey(),
                'admin_id' => $admin->getKey(),
                'mode' => $data->mode->value,
                'target_node_id' => $data->targetNodeId,
                'error_code' => self::refusalCode($refusal),
            ]);

            throw $refusal;
        }

        [$generation, $queued, $lost] = $outcome;

        foreach ($lost as [$planId, $generationId, $attemptId]) {
            $this->logger->warning('studio.discussion_generation.failed', [
                'studio_plan_id' => $planId,
                'generation_id' => $generationId,
                'attempt_id' => $attemptId,
                'status' => StudioDiscussionGenerationStatus::Failed->value,
                'error_code' => StudioDiscussionGenerationError::UnexpectedFailure->value,
                'reason' => 'lost',
            ]);
        }

        if (! $queued) {
            return $generation;
        }

        $this->logger->info('studio.discussion_generation.requested', [
            'studio_plan_id' => $generation->studio_plan_id,
            'generation_id' => $generation->id,
            'attempt_id' => $generation->attempt_id,
            'admin_id' => $admin->getKey(),
            'mode' => $generation->mode->value,
            'target_node_id' => $generation->target_node_id,
            'personas' => count($generation->persona_user_ids),
            'status' => $generation->status->value,
        ]);

        try {
            GenerateStudioDiscussionJob::dispatch($generation->id, $generation->attempt_id)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);

            // The queue could not take the job — or, run inline, it failed and
            // has already said so. Either way the generation ends here, and
            // asking again is the administrator's choice.
            $this->fail->handle($generation->id, $generation->attempt_id, StudioDiscussionGenerationError::UnexpectedFailure);
        }

        return $generation->refresh();
    }

    /**
     * @param  list<int>  $personaIds
     * @return array{0: StudioPlanDiscussionGeneration, 1: bool, 2: list<array{0: int, 1: int, 2: string}>}
     */
    private function queue(User $admin, StudioPlan $plan, StudioDiscussionGenerationData $data, array $personaIds, CarbonImmutable $now): array
    {
        $users = $this->lockUsersInOrder((int) $admin->getKey(), ...$personaIds);
        $lockedAdmin = $users->get($admin->getKey());

        if ($lockedAdmin === null || ! $lockedAdmin->can('manage-studio-plans')) {
            throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
        }

        $locked = $this->currentDraft((int) $plan->getKey(), $data->expectedLockVersion);
        $analysis = StudioPlanImageAnalysis::query()->where('studio_plan_id', $locked->id)->lockForUpdate()->first();

        // The role and the status that count are the ones the rows have now.
        foreach ($personaIds as $id) {
            $persona = $users->get($id);

            if ($persona === null || ! $persona->isPersona() || $persona->isTombstoned()) {
                throw new CannotRequestStudioDiscussionGenerationException(StudioDiscussionGenerationError::InvalidPersona);
            }
        }

        $context = StudioDiscussionContext::of($locked, $analysis, $personaIds);

        if (! $context instanceof StudioDiscussionContext) {
            throw new CannotRequestStudioDiscussionGenerationException($context);
        }

        $scope = $data->mode->isTargeted() ? $context->scope($data->mode, $data->targetNodeId) : [];

        if ($scope === null) {
            throw new CannotRequestStudioDiscussionGenerationException(StudioDiscussionGenerationError::InvalidTarget);
        }

        // The part's authors as the locked plan's discussion has them: the
        // rows locked must be theirs, or the part changed since it was read.
        if ($data->mode->isTargeted() && StudioDiscussionContext::authorsOf($locked, $scope) !== $personaIds) {
            throw CannotManageStudioPlanException::becausePlanChanged();
        }

        $onTheirWay = StudioPlanDiscussionGeneration::query()
            ->where('studio_plan_id', $locked->id)
            ->whereIn('status', [StudioDiscussionGenerationStatus::Queued->value, StudioDiscussionGenerationStatus::Running->value])
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        $underway = $onTheirWay->first(fn (StudioPlanDiscussionGeneration $generation): bool => $generation->isUnderway($now));

        if ($underway !== null) {
            return [$underway, false, []];
        }

        // Lost on their way: ended, so the new generation is the plan's only one.
        $lost = [];

        foreach ($onTheirWay as $generation) {
            self::saved($generation->forceFill([
                'status' => StudioDiscussionGenerationStatus::Failed,
                'error_code' => StudioDiscussionGenerationError::UnexpectedFailure,
                'finished_at' => $now,
            ]));
            $lost[] = [$generation->studio_plan_id, $generation->id, $generation->attempt_id];
        }

        $targetId = $scope[0] ?? null;
        $generation = new StudioPlanDiscussionGeneration;

        self::saved($generation->forceFill([
            'studio_plan_id' => $locked->id,
            'requested_by_user_id' => $lockedAdmin->id,
            'mode' => $data->mode,
            'target_node_id' => $targetId,
            'persona_user_ids' => $personaIds,
            'parameters' => [
                'mode' => $data->mode->value,
                'target_node_id' => $targetId,
                'persona_user_ids' => $personaIds,
                'root_count' => $data->mode->isTargeted() ? 0 : $data->rootCount,
                'reply_count' => $data->mode->isTargeted() ? 0 : $data->replyCount,
                'tone' => $data->tone->value,
                'expected_lock_version' => $data->expectedLockVersion,
                // The post's texts a whole tree is written for, so the editor
                // can say once they have changed since.
                ...($data->mode->isTargeted() ? [] : ['source_text_fingerprint' => StudioDiscussionContext::textFingerprint($locked->title, $locked->description)]),
            ],
            'source_lock_version' => $locked->lock_version,
            'source_image_asset_id' => $context->imageAssetId,
            'source_analysis_hash' => $context->analysisHash,
            'source_discussion_hash' => $context->discussionHash,
            'source_personas_hash' => $context->personasHash,
            'status' => StudioDiscussionGenerationStatus::Queued,
            'attempt_id' => (string) Str::uuid(),
            'requested_at' => $now,
        ]));

        return [$generation, true, $lost];
    }

    /**
     * The counts of a whole discussion within a discussion's bounds, and the
     * fields each mode does without left empty.
     *
     * @throws ValidationException keyed as the request's fields
     * @throws CannotRequestStudioDiscussionGenerationException when a whole discussion has nobody to write it
     */
    private function validate(StudioDiscussionGenerationData $data): void
    {
        $full = $data->mode === StudioDiscussionGenerationMode::FullDiscussion;

        if ($full && $data->personaUserIds === []) {
            throw new CannotRequestStudioDiscussionGenerationException(StudioDiscussionGenerationError::NoPersonas);
        }

        $mostNodes = min($this->generator->maxNodes(), StudioDiscussionValidationRules::MOST_NODES);
        $mostPersonas = $this->generator->maxPersonas();

        Validator::make($data->toArray(), $full ? [
            'personaUserIds' => ['list', 'max:'.$mostPersonas],
            'personaUserIds.*' => ['integer:strict', 'min:1', 'distinct:strict'],
            'targetNodeId' => ['prohibited'],
            'rootCount' => ['integer', 'min:1', 'max:'.$mostNodes],
            'replyCount' => ['integer', 'min:0', function (string $attribute, mixed $value, Closure $fail) use ($data, $mostNodes): void {
                if ($data->rootCount + $data->replyCount > $mostNodes) {
                    $fail("Keep the discussion to {$mostNodes} comments and replies or fewer.");
                }
            }],
        ] : [
            'targetNodeId' => ['required', 'integer:strict', 'min:1'],
        ], [
            'personaUserIds.max' => "Choose up to {$mostPersonas} personas.",
            'personaUserIds.*' => 'Choose each persona once.',
            'targetNodeId.prohibited' => 'A whole discussion is not written for one comment.',
            'targetNodeId.*' => 'Choose the comment to write again.',
            'rootCount.*' => "Ask for 1 to {$mostNodes} comments on the post.",
        ])->validate();
    }

    /**
     * Who takes part: the Personas chosen for a whole discussion; for a part
     * written again, the authors of its comments as the discussion has them
     * now — asked again once their rows are locked.
     *
     * @return list<int> ascending
     */
    private function participants(StudioPlan $plan, StudioDiscussionGenerationData $data): array
    {
        if (! $data->mode->isTargeted()) {
            $ids = array_values(array_unique(array_map(fn (mixed $id): int => (int) $id, $data->personaUserIds)));
            sort($ids);

            return $ids;
        }

        $scope = StudioDiscussionContext::scopeOf($plan, $data->mode, $data->targetNodeId);

        if ($scope === null) {
            throw new CannotRequestStudioDiscussionGenerationException(StudioDiscussionGenerationError::InvalidTarget);
        }

        return StudioDiscussionContext::authorsOf($plan, $scope);
    }

    private static function refusalCode(Throwable $refusal): string
    {
        return match (true) {
            $refusal instanceof CannotRequestStudioDiscussionGenerationException => $refusal->error->value,
            $refusal instanceof CannotManageStudioPlanException && $refusal->isStale() => StudioDiscussionGenerationError::StaleVersion->value,
            $refusal instanceof CannotManageStudioPlanException && $refusal->isNotDraft() => StudioDiscussionGenerationError::PlanClosed->value,
            $refusal instanceof CannotManageStudioPlanException && $refusal->isGone() => 'plan_gone',
            $refusal instanceof CannotManageStudioPlanException => 'access_denied',
            default => 'invalid_request',
        };
    }

    /**
     * A save a model event cancels returns false rather than throwing; here
     * it fails the whole request, as an exception would.
     */
    private static function saved(Model $model): void
    {
        if (! $model->save()) {
            throw new RuntimeException('A write of the discussion generation was not saved: '.$model::class.'.');
        }
    }
}
