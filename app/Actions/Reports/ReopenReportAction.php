<?php

namespace App\Actions\Reports;

use App\Enums\ReportStatus;
use App\Exceptions\Reports\CannotReopenReportException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Opens a resolved or ignored report again, for a moderator who closed it by
 * mistake — Reopen, or Undo right after closing it. Who closed it, when and
 * with which note go with the decision; the report is as it was when it came
 * in.
 */
final class ReopenReportAction
{
    use LocksActorForWrite;

    /**
     * @return bool whether this call opened it: false when the report was
     *              open already, or is gone — purged while this waited
     */
    public function handle(User $moderator, Report $report): bool
    {
        if (! $moderator->can('reopen', $report)) {
            throw CannotReopenReportException::becauseUserIsNotAllowed();
        }

        // Atomic and idempotent, as closing is: only a report that is still
        // closed is reopened, and the moderator is re-read under lock first
        // (Actor User -> Report), so a stale request from a just-sanctioned
        // moderator changes nothing.
        $updated = DB::transaction(function () use ($moderator, $report): int {
            $lockedActor = $this->lockActor($moderator);

            if ($lockedActor === null || ! Gate::forUser($lockedActor)->allows('reopen', $report)) {
                throw CannotReopenReportException::becauseUserIsNotAllowed();
            }

            return Report::query()
                ->whereKey($report->getKey())
                ->where('status', '!=', ReportStatus::Open->value)
                ->update([
                    'status' => ReportStatus::Open->value,
                    'resolved_by' => null,
                    'resolved_at' => null,
                    'resolution_note' => null,
                ]);
        });

        if ($updated === 0) {
            return false;
        }

        $report->refresh();

        return true;
    }
}
