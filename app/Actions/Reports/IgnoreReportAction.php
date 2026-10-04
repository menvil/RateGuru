<?php

namespace App\Actions\Reports;

use App\Enums\ReportStatus;
use App\Exceptions\Reports\CannotIgnoreReportException;
use App\Models\Concerns\LocksActorForWrite;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class IgnoreReportAction
{
    use LocksActorForWrite;

    public function handle(User $moderator, Report $report, ?string $note = null): void
    {
        if (! $moderator->can('ignore', $report)) {
            throw CannotIgnoreReportException::becauseUserIsNotAllowed();
        }

        $note = trim((string) $note);
        $note = $note === '' ? null : $note;

        // Atomic, idempotent ignore: only the first writer whose row still
        // matches `status = open` wins. Concurrent or repeated calls match
        // zero rows and never overwrite the original processor metadata.
        // The reports table has no dedicated ignored_* columns, so the
        // resolved_by/resolved_at/resolution_note triplet is reused as
        // generic "processor" metadata across resolve and ignore — same
        // convention ResolveReportAction follows.
        // Actor User -> Report: a stale request from a just-sanctioned
        // moderator must not process reports.
        $updated = DB::transaction(function () use ($moderator, $report, $note): int {
            $lockedActor = $this->lockActor($moderator);

            if ($lockedActor === null || ! Gate::forUser($lockedActor)->allows('ignore', $report)) {
                throw CannotIgnoreReportException::becauseUserIsNotAllowed();
            }

            return Report::query()
                ->whereKey($report->getKey())
                ->where('status', ReportStatus::Open->value)
                ->update([
                    'status' => ReportStatus::Ignored->value,
                    'resolved_by' => $lockedActor->id,
                    'resolved_at' => now(),
                    'resolution_note' => $note,
                ]);
        });

        if ($updated > 0) {
            $report->refresh();
        }
    }
}
