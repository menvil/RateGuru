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
 * An administrator takes the photo off a Draft. Only the plan's reference
 * goes: in the same transaction the photo is released through the media
 * lifecycle — soft-deleted when nothing else (a post, an avatar, another
 * plan) references it, its files purged after the grace period — and kept
 * as it is when something does. No post or Persona is touched.
 *
 * The administrator's row and then the plan are locked (LocksStudioPlanDraft)
 * and everything is asked again on them: the administrator may still manage
 * plans, and the plan is still a Draft at the version the editor was drawn
 * from (`$expectedLockVersion`). A plan with no photo is left as it is.
 */
final class RemoveStudioPlanImageAction
{
    use LocksStudioPlanDraft;

    public function __construct(
        private readonly MediaLifecycleService $lifecycleService,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  int  $expectedLockVersion  the plan's lock_version as the editor was drawn from it
     *
     * @throws CannotManageStudioPlanException when the administrator may not, or the plan is gone, past its Draft or changed meanwhile
     */
    public function handle(User $admin, StudioPlan $plan, int $expectedLockVersion): void
    {
        if (! $admin->can('manage-studio-plans')) {
            throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
        }

        $removedAssetId = DB::transaction(function () use ($admin, $plan, $expectedLockVersion): ?int {
            $this->lockAdministratorAndAuthor($admin);
            $locked = $this->currentDraft((int) $plan->getKey(), $expectedLockVersion);
            $previousAssetId = $locked->image_asset_id;

            if ($previousAssetId === null) {
                return null;
            }

            $locked->forceFill([
                'image_asset_id' => null,
                'lock_version' => $locked->lock_version + 1,
            ])->save();

            $this->lifecycleService->releaseUnreferenced(collect([$previousAssetId]));

            $plan->setRawAttributes($locked->getAttributes(), true);
            $plan->unsetRelation('imageAsset');

            return $previousAssetId;
        });

        if ($removedAssetId !== null) {
            $this->logger->info('studio_plan.image.removed', [
                'studio_plan_id' => $plan->id,
                'admin_id' => $admin->id,
                'media_asset_id' => $removedAssetId,
            ]);
        }
    }
}
