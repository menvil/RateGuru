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

        // Atomic, idempotent resolution: only the first writer whose row still
        // matches `status != resolved` wins. Concurrent or repeated calls match
        // zero rows and never overwrite the original resolver metadata. The
        // moderator is re-read under lock first (Actor User -> Report): a
        // stale request from a just-sanctioned moderator must not process
        // reports.
        $updated = DB::transaction(function () use ($moderator, $report, $note): int {
            $lockedActor = $this->lockActor($moderator);

            if ($lockedActor === null || ! Gate::forUser($lockedActor)->allows('resolve', $report)) {
                throw CannotResolveReportException::becauseUserIsNotAllowed();
            }

            return Report::query()
                ->whereKey($report->getKey())
                ->where('status', '!=', ReportStatus::Resolved->value)
                ->update([
                    'status' => ReportStatus::Resolved->value,
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
