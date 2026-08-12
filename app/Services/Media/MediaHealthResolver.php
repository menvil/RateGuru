<?php

namespace App\Services\Media;

use App\Enums\MediaAuditRunStatus;
use App\Enums\MediaHealthStatus;
use App\Models\MediaAuditRun;

/**
 * Centralizes the one health mapping every caller (widget, diagnostics page)
 * must agree on — deliberately not a scoring/weighting engine, just the
 * three rules below, in order. Pure: takes the latest *completed* run
 * (fetching it, e.g. MediaAuditRun::where('status', Completed)
 * ->latest('completed_at')->first(), is the caller's job) rather than
 * querying itself, so it's trivially unit-testable against a handful of
 * MediaAuditRun states without any DB setup.
 */
final class MediaHealthResolver
{
    public function resolve(?MediaAuditRun $latestCompletedRun): MediaHealthStatus
    {
        if ($latestCompletedRun === null || $latestCompletedRun->status !== MediaAuditRunStatus::Completed) {
            return MediaHealthStatus::Unknown;
        }

        if ($latestCompletedRun->missing_masters > 0) {
            return MediaHealthStatus::Critical;
        }

        $warningSignals = $latestCompletedRun->missing_variant_files
            + $latestCompletedRun->active_unreferenced_assets
            + $latestCompletedRun->physical_orphan_candidates
            + $latestCompletedRun->failed_media_jobs;

        if ($warningSignals > 0) {
            return MediaHealthStatus::Warning;
        }

        // purgeable_assets is deliberately excluded: it's a normal,
        // self-resolving lifecycle state (info severity), not a problem —
        // a clean audit can still report purgeable assets and stay healthy.
        return MediaHealthStatus::Healthy;
    }
}
