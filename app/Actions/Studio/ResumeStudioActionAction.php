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
 * Resume in the Action queue: a held action — its Persona Limited or
 * Shadowbanned when it ran — sent to run again once an administrator decided
 * it should (docs/architecture/studio-plans.md, "The Action queue").
 *
 * Only a Held action is resumed. It becomes Pending, as approval left it, its
 * attempts counted afresh (ManagesStudioActions::restart()); the run asks the
 * Persona again, so one still restricted is held again rather than written
 * past its restriction. The dispatcher is woken once it has committed.
 */
final class ResumeStudioActionAction
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

        $action = DB::transaction(function () use ($actionId): StudioAction {
            $action = self::lockedIn($actionId, [StudioActionStatus::Held]);
            self::restart($action);

            return $action;
        });

        $this->logger->info('studio.action.resumed', [
            'studio_action_id' => $action->id,
            'studio_plan_id' => $action->studio_plan_id,
            'admin_id' => $admin->getKey(),
        ]);
        $this->wake->now();

        return $action;
    }
}
