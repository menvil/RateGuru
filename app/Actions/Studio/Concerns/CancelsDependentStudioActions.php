<?php

namespace App\Actions\Studio\Concerns;

use App\Enums\StudioActionFailure;
use App\Enums\StudioActionStatus;
use App\Models\StudioAction;
use RuntimeException;

/**
 * How an action cancelled for good takes its branch with it
 * (docs/architecture/studio-plans.md, "Running a plan"): every action that
 * depends on it, however deep — a post's comments, their replies — and still
 * waits for it (Pending, Held or Dispatched) is cancelled too,
 * `parent_cancelled`, in the transaction that cancels the action itself.
 * Nothing done is touched, nor anything beside the branch — another comment
 * of the post, another comment's replies. Asked again, it finds nothing more
 * to cancel.
 *
 * An action of the branch already Running is left to its own run, which asks
 * its parent again before it writes anything and finds it cancelled.
 *
 * The rows are locked level by level, each level in ascending id order, after
 * the cancelled action's own row and before its plan's — the order every
 * writer of a plan's actions takes them in — so a cascade and a claim, or two
 * cascades, take turns rather than deadlock.
 */
trait CancelsDependentStudioActions
{
    /**
     * @return list<int> the actions cancelled now
     */
    private function cancelBranchBelow(StudioAction $action): array
    {
        $cancelled = [];
        $parents = [$action->id];

        while ($parents !== []) {
            $children = StudioAction::query()
                ->whereIn('parent_action_id', $parents)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $parents = $children->modelKeys();

            foreach ($children as $child) {
                if (! in_array($child->status, [StudioActionStatus::Pending, StudioActionStatus::Held, StudioActionStatus::Dispatched], true)) {
                    continue;
                }

                $saved = $child->forceFill([
                    'status' => StudioActionStatus::Cancelled,
                    'failure_code' => StudioActionFailure::ParentCancelled->value,
                    'failure_reason' => null,
                    'finished_at' => now(),
                ])->save();

                if (! $saved) {
                    throw new RuntimeException('A studio action depending on one cancelled was not saved as cancelled.');
                }

                $cancelled[] = (int) $child->id;
            }
        }

        return $cancelled;
    }
}
