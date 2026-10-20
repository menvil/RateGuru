<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\LocksStudioPlanDraft;
use App\Data\Studio\StudioPlanData;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Studio\StudioPlanValidationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An administrator's save of a Draft: its author, texts, category, tags, the
 * author's answers and when it is to be published — everything StudioPlanData
 * holds, and nothing else. The photo has actions of its own, and changing the
 * author leaves it as it is: the photo is the uploading administrator's, not
 * the Persona's, so nothing is moved, stored or generated again.
 *
 * Saving a date publishes nothing: a plan is published only once approved.
 *
 * The administrator's row and the chosen author's are locked in ascending id
 * order, then the plan (LocksStudioPlanDraft), and everything is asked again
 * on them: the administrator may still manage plans, the author is still a
 * Persona that is not deleted — a Limited, Banned or Shadowbanned one still
 * may be, whether it can publish is asked on approval — and the plan is still
 * a Draft at the version the editor was drawn from (`$expectedLockVersion`),
 * so a save made before somebody else's is refused rather than written over
 * it. The save raises the version.
 */
final class UpdateStudioPlanAction
{
    use LocksStudioPlanDraft;

    public function __construct(
        private readonly StudioPlanValidationRules $rules,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  int  $expectedLockVersion  the plan's lock_version as the editor was drawn from it
     *
     * @throws CannotManageStudioPlanException when the administrator may not, the author is no Persona to choose, or the plan is gone, past its Draft or changed meanwhile
     * @throws ValidationException keyed as the editor's fields
     */
    public function handle(User $admin, StudioPlan $plan, StudioPlanData $changes, int $expectedLockVersion): void
    {
        if (! $admin->can('manage-studio-plans')) {
            throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
        }

        $changes = $this->rules->validated($changes);

        DB::transaction(function () use ($admin, $plan, $changes, $expectedLockVersion): void {
            $this->lockAdministratorAndAuthor($admin, $changes->authorUserId);
            $locked = $this->currentDraft((int) $plan->getKey(), $expectedLockVersion);

            $locked->fill($changes->attributes());
            $locked->forceFill(['lock_version' => $locked->lock_version + 1])->save();

            $plan->setRawAttributes($locked->getAttributes(), true);
            $plan->unsetRelations();
        });

        $this->logger->info('studio_plan.updated', [
            'studio_plan_id' => $plan->id,
            'admin_id' => $admin->id,
        ]);
    }
}
