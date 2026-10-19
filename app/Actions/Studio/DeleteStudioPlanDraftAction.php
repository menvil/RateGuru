<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\LocksStudioPlanDraft;
use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\StudioPlan;
use App\Models\User;
use App\Services\Media\MediaLifecycleService;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;

/**
 * An administrator deletes a Draft. A Draft has no history yet — nothing was
 * published or run from it — so its row is deleted outright; a plan past its
 * Draft is never deleted here.
 *
 * The administrator's row and then the plan are locked (LocksStudioPlanDraft)
 * and everything is asked again on them: the administrator may still manage
 * plans, and the plan is still a Draft at the version the editor was drawn
 * from (`$expectedLockVersion`). In the same transaction the plan's photo is
 * released through the media lifecycle — soft-deleted when nothing else
 * references it, its files purged after the grace period — and kept as it is
 * when something does. No post or Persona is touched.
 *
 * A second delete of the same plan finds nothing to lock and says so
 * (`isGone()`), releasing nothing again.
 */
final class DeleteStudioPlanDraftAction
{
    use LocksStudioPlanDraft;

    public function __construct(
        private readonly MediaLifecycleService $lifecycleService,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  int  $expectedLockVersion  the plan's lock_version as the administrator saw it
     *
     * @throws CannotManageStudioPlanException when the administrator may not, or the plan is gone, past its Draft or changed meanwhile
     */
    public function handle(User $admin, StudioPlan $plan, int $expectedLockVersion): void
    {
        if (! $admin->can('manage-studio-plans')) {
            throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
        }

        $assetId = DB::transaction(function () use ($admin, $plan, $expectedLockVersion): ?int {
            $this->lockAdministratorAndAuthor($admin);
            $locked = $this->currentDraft((int) $plan->getKey(), $expectedLockVersion);
            $assetId = $locked->image_asset_id;

            $locked->delete();

            if ($assetId !== null) {
                $this->lifecycleService->releaseUnreferenced(collect([$assetId]));
            }

            return $assetId;
        });

        $plan->exists = false;

        $this->logger->info('studio_plan.deleted', [
            'studio_plan_id' => $plan->id,
            'admin_id' => $admin->id,
            'media_asset_id' => $assetId,
        ]);
    }
}
