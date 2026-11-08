<?php

namespace App\Actions\Reports;

use App\Enums\ReportStatus;
use App\Exceptions\Reports\CannotResolveReportException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ResolveReportAction
{
    use LocksActorForWrite;

    public function handle(User $moderator, Report $report, ?string $note = null): void
    {
        if (! $moderator->can('resolve', $report)) {
            throw CannotResolveReportException::becauseUserIsNotAllowed();
        }

        $note = trim((string) $note);
        $note = $note === '' ? null : $note;

        // Atomic resolution: only the first writer whose row is still open
        // wins, as with ignoring. Concurrent or repeated calls match zero rows
        // and never overwrite the metadata of whoever closed it first — an
        // ignored report included: turning it into a resolved one is a reopen
        // and a new decision, not a second close. The moderator is re-read
        // under lock first (Actor User -> Report): a stale request from a
        // just-sanctioned moderator must not process reports.
        $updated = DB::transaction(function () use ($moderator, $report, $note): int {
            $lockedActor = $this->lockActor($moderator);

            if ($lockedActor === null || ! Gate::forUser($lockedActor)->allows('resolve', $report)) {
                throw CannotResolveReportException::becauseUserIsNotAllowed();
            }

            return Report::query()
                ->whereKey($report->getKey())
                ->where('status', ReportStatus::Open->value)
                ->update([
                    'status' => ReportStatus::Resolved->value,
                    'resolved_by' => $lockedActor->id,
                    'resolved_at' => now(),
                    'resolution_note' => $note,
                ]);
        });

        if ($updated === 0) {
            throw CannotResolveReportException::becauseItIsNoLongerOpen();
        }

        $report->refresh();
    }
}
