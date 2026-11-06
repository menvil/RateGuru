<?php

namespace App\Actions\Studio;

use App\Actions\Studio\Concerns\ManagesStudioActions;
use App\Enums\StudioActionStatus;
use App\Exceptions\Studio\CannotManageStudioActionException;
use App\Models\StudioAction;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use App\Support\Studio\StudioDispatchWake;
use Illuminate\Support\Facades\DB;

/**
 * Retry in the Action queue: a failed action — an unexpected error, a vote in
 * conflict, its attempts or its Persona's limit spent, or its approver no
 * longer allowed — sent to run again once an administrator decided it should
 * (docs/architecture/studio-plans.md, "The Action queue").
 *
 * Only a Failed action is retried: one done is never run again, and one
 * cancelled stays cancelled. It becomes Pending, as approval left it, its
 * attempts counted afresh (ManagesStudioActions::restart()); nothing of its
 * dates moves, and every check of the run asks again — a vote still in
 * conflict fails again rather than taking anything back. The dispatcher is
 * woken once it has committed, so it runs now rather than at the next minute.
 */
final class RetryStudioActionAction
{
    use ManagesStudioActions;

    public function __construct(
        private readonly StudioDispatchWake $wake,
        private readonly DomainLogger $logger,
    ) {}

    /** @throws CannotManageStudioActionException with nothing written */
    public function handle(User $admin, int $actionId): StudioAction
    {
        self::ensureAllowed($admin);

        $action = DB::transaction(function () use ($actionId): array {
            $action = self::lockedIn($actionId, [StudioActionStatus::Failed]);
            $failure = $action->failure_code;
            self::restart($action);

            return [$action, $failure];
        });

        $this->logger->info('studio.action.retried', [
            'studio_action_id' => $action[0]->id,
            'studio_plan_id' => $action[0]->studio_plan_id,
            'admin_id' => $admin->getKey(),
            'failure_code' => $action[1],
        ]);
        $this->wake->now();

        return $action[0];
    }
}
