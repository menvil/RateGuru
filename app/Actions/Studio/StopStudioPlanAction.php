<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\ManagesStudioActions;
use App\Actions\Studio\Concerns\SettlesStudioPlan;
use App\Enums\StudioActionFailure;
use App\Enums\StudioActionStatus;
use App\Enums\StudioPlanStatus;
use App\Exceptions\Studio\CannotManageStudioActionException;
use App\Models\StudioAction;
use App\Models\StudioPlan;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stop remaining actions: everything of an approved or running plan not yet
 * run — waiting, held, failed, or sent and not yet claimed — cancelled for
 * good by an administrator (`cancelled_by_administrator`)
 * (docs/architecture/studio-plans.md, "The Action queue").
 *
 * Nothing is taken back and nothing goes back to a draft: a post already
 * published stays, and so do its comments and votes already written. Its
 * post not yet published, the plan never publishes it. A run already claimed
 * is left to finish — whatever depended on it is cancelled here, so nothing
 * follows it. The plan is then settled: Completed when its post was
 * published, Cancelled when it never will be, or still Running while a
 * claimed run finishes.
 *
 * The plan's actions are locked in ascending id order — every parent before
 * its children, as a cascade takes them — then the plan.
 */
final class StopStudioPlanAction
{
    use ManagesStudioActions;
    use SettlesStudioPlan;

    public function __construct(
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @return list<int> the actions cancelled
     *
     * @throws CannotManageStudioActionException with nothing written
     */
    public function handle(User $admin, int $planId): array
    {
        self::ensureAllowed($admin);

        $cancelled = DB::transaction(function () use ($planId): array {
            $status = StudioPlan::query()->find($planId)?->status;

            if ($status === null) {
                throw CannotManageStudioActionException::becausePlanIsGone();
            }

            if (! in_array($status, [StudioPlanStatus::Approved, StudioPlanStatus::Running], true)) {
                throw CannotManageStudioActionException::becausePlanIsNotRunning();
            }

            $open = StudioAction::query()
                ->where('studio_plan_id', $planId)
                ->whereIn('status', [StudioActionStatus::Pending, StudioActionStatus::Held, StudioActionStatus::Failed, StudioActionStatus::Dispatched])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $cancelled = [];

            foreach ($open as $action) {
                $saved = $action->forceFill([
                    'status' => StudioActionStatus::Cancelled,
                    'failure_code' => StudioActionFailure::CancelledByAdministrator->value,
                    'failure_reason' => null,
                    'finished_at' => now(),
                ])->save();

                if (! $saved) {
                    throw new RuntimeException('An action of the stopped plan was not saved as cancelled.');
                }

                $cancelled[] = (int) $action->id;
            }

            $plan = StudioPlan::query()->whereKey($planId)->lockForUpdate()->first();

            if ($plan === null) {
                throw CannotManageStudioActionException::becausePlanIsGone();
            }

            $this->settlePlan($plan);

            return $cancelled;
        });

        $this->logger->warning('studio_plan.stopped', [
            'studio_plan_id' => $planId,
            'admin_id' => $admin->getKey(),
            'cancelled' => count($cancelled),
        ]);

        return $cancelled;
    }
}
