<?php

namespace App\Actions\Reports;

use App\Enums\ReportStatus;
use App\Exceptions\Reports\CannotIgnoreReportException;
use App\Models\Report;
use App\Models\User;

final class IgnoreReportAction
{
    public function handle(User $moderator, Report $report, ?string $note = null): void
    {
        if (! $moderator->isModerator() && ! $moderator->isAdmin()) {
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
        $updated = Report::query()
            ->whereKey($report->getKey())
            ->where('status', ReportStatus::Open->value)
            ->update([
                'status' => ReportStatus::Ignored->value,
                'resolved_by' => $moderator->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ]);

        if ($updated > 0) {
            $report->refresh();
        }
    }
}
