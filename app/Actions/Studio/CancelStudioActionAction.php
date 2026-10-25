<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\CancelsDependentStudioActions;
use App\Actions\Studio\Concerns\ManagesStudioActions;
use App\Actions\Studio\Concerns\SettlesStudioPlan;
use App\Enums\StudioActionFailure;
use App\Enums\StudioActionStatus;
use App\Exceptions\Studio\CannotManageStudioActionException;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cancel in the Action queue: an action not yet run — waiting, held, failed,
 * or sent and not yet claimed — cancelled for good by an administrator
 * (`cancelled_by_administrator`), and with it every action that depends on it
 * and still waits (CancelsDependentStudioActions): a post's comments and
 * votes, a comment's replies and votes (docs/architecture/studio-plans.md,
 * "The Action queue").
 *
 * What is done stays done: an action done is never cancelled, so a published
 * post, comment or vote is never taken back here, and the branch of one
 * cancelled leaves whatever of it ran already. A run already claimed is left
 * to finish — nothing promises to stop a worker mid-write — and a job sent
 * before the cancellation claims nothing when it arrives. The plan is settled
 * once nothing of it is left: Completed when its post was published,
 * Cancelled when it never will be.
 *
 * The action's row, then its branch level by level, then the plan — the order
 * every writer of a plan's actions takes them in.
 */
final class CancelStudioActionAction
{
    use CancelsDependentStudioActions;
    use ManagesStudioActions;
    use SettlesStudioPlan;

    public function __construct(
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @return list<int> the actions of its branch cancelled with it
     *
     * @throws CannotManageStudioActionException with nothing written
     */
    public function handle(User $admin, int $actionId): array
    {
        self::ensureAllowed($admin);

        [$action, $branch] = DB::transaction(function () use ($actionId): array {
            $action = self::lockedIn($actionId, [StudioActionStatus::Pending, StudioActionStatus::Held, StudioActionStatus::Failed, StudioActionStatus::Dispatched]);

            $saved = $action->forceFill([
                'status' => StudioActionStatus::Cancelled,
                'failure_code' => StudioActionFailure::CancelledByAdministrator->value,
                'failure_reason' => null,
                'finished_at' => now(),
            ])->save();

            if (! $saved) {
                throw new RuntimeException('The studio action was not saved as cancelled.');
            }

            $branch = $this->cancelBranchBelow($action);
            $plan = StudioPlan::query()->whereKey($action->studio_plan_id)->lockForUpdate()->first();

            if ($plan !== null) {
                $this->settlePlan($plan);
            }

            return [$action, $branch];
        });

        $this->logger->warning('studio.action.cancelled', [
            'studio_action_id' => $action->id,
            'studio_plan_id' => $action->studio_plan_id,
            'admin_id' => $admin->getKey(),
            'status' => StudioActionStatus::Cancelled->value,
            'failure_code' => StudioActionFailure::CancelledByAdministrator->value,
        ]);

        foreach ($branch as $id) {
            $this->logger->warning('studio.action.cancelled', [
                'studio_action_id' => $id,
                'status' => StudioActionStatus::Cancelled->value,
                'failure_code' => StudioActionFailure::ParentCancelled->value,
            ]);
        }

        return $branch;
    }
}
