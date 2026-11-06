<?php

namespace App\Actions\Studio\Concerns;

use App\Enums\StudioActionStatus;
use App\Enums\StudioActionType;
use App\Enums\StudioPlanStatus;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use RuntimeException;

/**
 * Where a plan stands once one of its actions has moved on — by its run, or by
 * an administrator in the Action queue (docs/architecture/studio-plans.md,
 * "Running a plan"). Called on the plan's locked row, in the transaction that
 * moved the action.
 */
trait SettlesStudioPlan
{
    /**
     * The plan, once nothing of it is left to run or to decide: Completed when
     * its post was published — its comments and votes done, or cancelled for
     * good — Cancelled when the post never will be. An action still waiting,
     * or held or failed for an administrator, keeps the plan as it is; one
     * comment's failure takes back nothing already published.
     */
    private function settlePlan(StudioPlan $plan): void
    {
        $open = StudioAction::query()
            ->where('studio_plan_id', $plan->id)
            ->whereIn('status', [StudioActionStatus::Pending, StudioActionStatus::Held, StudioActionStatus::Dispatched, StudioActionStatus::Running, StudioActionStatus::Failed])
            ->exists();

        if ($open) {
            return;
        }

        $published = StudioAction::query()
            ->where('studio_plan_id', $plan->id)
            ->where('type', StudioActionType::CreatePost)
            ->where('status', StudioActionStatus::Done)
            ->exists();

        $settled = $plan->forceFill(['status' => $published ? StudioPlanStatus::Completed : StudioPlanStatus::Cancelled])->save();

        if (! $settled) {
            throw new RuntimeException('The publication plan was not saved as settled.');
        }
    }
}
