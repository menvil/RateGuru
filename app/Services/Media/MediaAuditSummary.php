<?php

namespace App\Services\Media;

/**
 * Aggregate counts from one MediaAuditService::run() call — the same shape
 * as media_audit_runs' own counter columns, so RunMediaAuditJob can persist
 * this directly and MediaAuditCommand can print it directly.
 */
final readonly class MediaAuditSummary
{
    public function __construct(
        public int $assetsChecked,
        public int $variantsChecked,
        public int $healthyAssets,
        public int $activeUnreferencedAssets,
        public int $softDeletedWithinGrace,
        public int $purgeableAssets,
        public int $missingMasters,
        public int $missingVariantFiles,
        public int $physicalOrphanCandidates,
        public int $failedMediaJobs,
    ) {}
}
