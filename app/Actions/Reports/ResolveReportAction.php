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

        if ($report->status === ReportStatus::Resolved) {
            return;
        }

        $note = trim((string) $note);
        $note = $note === '' ? null : $note;

        $report->forceFill([
            'status' => ReportStatus::Resolved,
            'resolved_by' => $moderator->id,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ])->save();
    }
}
