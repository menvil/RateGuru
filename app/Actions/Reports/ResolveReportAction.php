<?php

namespace App\Actions\Reports;

use App\Enums\ReportStatus;
use App\Exceptions\Reports\CannotResolveReportException;
use App\Models\Report;
use App\Models\User;

final class ResolveReportAction
{
    public function handle(User $moderator, Report $report, ?string $note = null): void
    {
        if (! $moderator->isModerator() && ! $moderator->isAdmin()) {
            throw CannotResolveReportException::becauseUserIsNotAllowed();
        }

        $note = trim((string) $note);
        $note = $note === '' ? null : $note;

        // Atomic, idempotent resolution: only the first writer whose row still
        // matches `status != resolved` wins. Concurrent or repeated calls match
        // zero rows and never overwrite the original resolver metadata.
        $updated = Report::query()
            ->whereKey($report->getKey())
            ->where('status', '!=', ReportStatus::Resolved->value)
            ->update([
                'status' => ReportStatus::Resolved->value,
                'resolved_by' => $moderator->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ]);

        if ($updated > 0) {
            $report->refresh();
        }
    }
}
