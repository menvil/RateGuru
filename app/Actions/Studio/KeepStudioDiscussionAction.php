<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\LocksStudioPlanDraft;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Models\User;
use App\Support\StudioAi\StudioDiscussionContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Keep as is (docs/architecture/studio-plans.md, "The editor"): an
 * administrator keeps a plan's discussion although the post's final title or
 * description changed after the whole tree taken in last was written for
 * them. That generation keeps the texts it is kept for
 * (`kept_text_fingerprint` in its parameters), so the editor stops saying so
 * until they change again. Nothing of the plan changes — not its discussion,
 * not its version.
 *
 * In one transaction, in the project's locking order: the administrator's
 * `users` row, then the plan, still a Draft at the version the editor was
 * drawn from, then the generation. Without a whole tree taken in there is
 * nothing to keep, and nothing is written.
 */
final class KeepStudioDiscussionAction
{
    use LocksStudioPlanDraft;

    /** @throws CannotManageStudioPlanException when the administrator may not, or the plan is gone, past its Draft or changed meanwhile */
    public function handle(User $admin, StudioPlan $plan, int $expectedLockVersion): void
    {
        if (! $admin->can('manage-studio-plans')) {
            throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $plan, $expectedLockVersion): void {
            $this->lockAdministratorAndAuthor($admin);
            $locked = $this->currentDraft((int) $plan->getKey(), $expectedLockVersion);

            $generation = StudioPlanDiscussionGeneration::lastTreeTakenIn($locked->id, lock: true);

            if ($generation === null) {
                return;
            }

            $generation->forceFill(['parameters' => [
                ...$generation->parameters,
                'kept_text_fingerprint' => StudioDiscussionContext::textFingerprint($locked->title, $locked->description),
            ]]);

            if (! $generation->save()) {
                throw new RuntimeException('A discussion kept for the post\'s texts was not saved.');
            }
        });
    }
}
