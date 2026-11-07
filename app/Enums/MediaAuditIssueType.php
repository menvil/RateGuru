<?php

namespace App\Enums;

enum MediaAuditIssueType: string
{
    case ActiveUnreferencedAsset = 'active_unreferenced_asset';
    case MissingMasterFile = 'missing_master_file';
    case MissingVariantFile = 'missing_variant_file';
    case PurgeableAsset = 'purgeable_asset';
    case PhysicalOrphanCandidate = 'physical_orphan_candidate';
    case FailedGenerationJob = 'failed_generation_job';

    /**
     * soft_deleted_within_grace is a normal lifecycle state, not an issue —
     * it's reported in MediaAuditRun's own counters but deliberately has no
     * case here and never produces a MediaAuditIssue row.
     */
    public function severity(): MediaAuditIssueSeverity
    {
        return match ($this) {
            self::MissingMasterFile => MediaAuditIssueSeverity::Critical,
            self::MissingVariantFile,
            self::ActiveUnreferencedAsset,
            self::PhysicalOrphanCandidate,
            self::FailedGenerationJob => MediaAuditIssueSeverity::Warning,
            self::PurgeableAsset => MediaAuditIssueSeverity::Info,
        };
    }
}
