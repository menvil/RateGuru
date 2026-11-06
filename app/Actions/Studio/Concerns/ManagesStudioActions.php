<?php

namespace App\Actions\Studio\Concerns;

use App\Enums\StudioActionStatus;
use App\Exceptions\Studio\CannotManageStudioActionException;
use App\Models\StudioAction;
use App\Models\User;
use RuntimeException;

/**
 * What an administrator's operation in the Action queue asks before it
 * writes (RetryStudioActionAction, ResumeStudioActionAction,
 * CancelStudioActionAction, StopStudioPlanAction).
 *
 * The administrator is asked of their row as it is now, without a lock: the
 * executor locks an action's row before any user's — the approving
 * administrator's among them, for a post it publishes — so an operation that
 * locked its administrator first and the action second could wait on a run
 * that waits on it. Action rows are locked before their plan's, as every
 * writer of a plan's actions takes them.
 */
trait ManagesStudioActions
{
    /** @throws CannotManageStudioActionException */
    private static function ensureAllowed(User $admin): void
    {
        $current = User::query()->find($admin->getKey());

        if ($current === null || ! $current->can('manage-studio-plans')) {
            throw CannotManageStudioActionException::becauseUserIsNotAllowed();
        }
    }

    /**
     * The action, locked, when it is in one of these states.
     *
     * @param  list<StudioActionStatus>  $states
     *
     * @throws CannotManageStudioActionException
     */
    private static function lockedIn(int $actionId, array $states): StudioAction
    {
        $action = StudioAction::query()->whereKey($actionId)->lockForUpdate()->first();

        if ($action === null) {
            throw CannotManageStudioActionException::becauseActionIsGone();
        }

        if (! in_array($action->status, $states, true)) {
            throw CannotManageStudioActionException::becauseOfItsStatus($action->status);
        }

        return $action;
    }

    /**
     * Pending again, as approval left it, its attempts and the sends its
     * Persona's limits sent back counted afresh — a new start an
     * administrator decided on, which every check of the run still asks.
     * The action it depends on must be done: one cancelled, or not done,
     * would only leave it waiting.
     *
     * @throws CannotManageStudioActionException
     */
    private static function restart(StudioAction $action): void
    {
        if ($action->parent_action_id !== null
            && StudioAction::query()->find($action->parent_action_id)?->status !== StudioActionStatus::Done) {
            throw CannotManageStudioActionException::becauseParentIsNotDone();
        }

        $saved = $action->forceFill([
            'status' => StudioActionStatus::Pending,
            'attempts' => 0,
            'deferrals' => 0,
            'dispatched_at' => null,
            'claimed_at' => null,
            'finished_at' => null,
            'failure_code' => null,
            'failure_reason' => null,
        ])->save();

        if (! $saved) {
            throw new RuntimeException('The studio action was not saved as pending again.');
        }
    }
}
