<?php

namespace App\Services\Media;

use App\Enums\MediaAuditIssueType;
use App\Models\MediaAsset;

/**
 * The single implementation of a full media audit sweep — media:audit,
 * RunMediaAuditJob, and tests all call run() on this class; nothing
 * duplicates this logic elsewhere. Read-only: never mutates a MediaAsset/
 * MediaVariant row or a physical file. This is deliberately the *expensive*
 * audit (a full DB chunked scan plus a filesystem directory listing via
 * MediaOrphanScanner) — callers that must stay cheap (a Filament page
 * request) never call this directly; they read a MediaAuditRun snapshot
 * persisted by a prior RunMediaAuditJob run instead.
 */
final class MediaAuditService
{
    private const int DEFAULT_CHUNK_SIZE = 200;

    public function __construct(
        private readonly MediaReferenceChecker $referenceChecker,
        private readonly MediaLifecycleService $lifecycle,
        private readonly MediaOrphanScanner $orphanScanner,
        private readonly MediaStorage $storage,
        private readonly FailedMediaJobReader $failedJobReader,
    ) {}

    /**
     * @param  (callable(MediaAuditIssueData): void)|null  $onIssue  called
     *                                                               once per issue found, in discovery order — never held in
     *                                                               memory as one big collection here. Callers that only need the
     *                                                               returned MediaAuditSummary (the CLI's aggregate report) can
     *                                                               omit this entirely.
     */
    public function run(?callable $onIssue = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): MediaAuditSummary
    {
        $onIssue ??= static function (MediaAuditIssueData $issue): void {};

        $assetsChecked = 0;
        $variantsChecked = 0;
        $healthyAssets = 0;
        $activeUnreferencedAssets = 0;
        $softDeletedWithinGrace = 0;
        $purgeableAssets = 0;
        $missingMasters = 0;
        $missingVariantFiles = 0;

        MediaAsset::withTrashed()->with('variants')->orderBy('id')
            ->chunkById(max(1, $chunkSize), function ($assets) use (
                &$assetsChecked, &$variantsChecked, &$healthyAssets, &$activeUnreferencedAssets,
                &$softDeletedWithinGrace, &$purgeableAssets, &$missingMasters, &$missingVariantFiles,
                $onIssue,
            ): void {
                // Two queries per chunk (via referencedAssetIds()), not two
                // per asset — the same batching MediaAuditCommand already
                // relied on before this extraction.
                $referencedIds = $this->referenceChecker->referencedAssetIds($assets->pluck('id'));

                foreach ($assets as $asset) {
                    $assetsChecked++;
                    $variantsChecked += $asset->variants->count();

                    $isReferenced = $referencedIds->has($asset->id);

                    if ($asset->trashed()) {
                        if ($this->lifecycle->isGraceExpired($asset) && ! $isReferenced) {
                            $purgeableAssets++;
                            $onIssue(new MediaAuditIssueData(
                                issueType: MediaAuditIssueType::PurgeableAsset,
                                mediaAssetId: $asset->id,
                            ));
                        } else {
                            // Normal lifecycle state, not an issue — see
                            // MediaAuditIssueType's own docblock.
                            $softDeletedWithinGrace++;
                        }
                    } elseif ($isReferenced) {
                        $healthyAssets++;
                    } else {
                        $activeUnreferencedAssets++;
                        $onIssue(new MediaAuditIssueData(
                            issueType: MediaAuditIssueType::ActiveUnreferencedAsset,
                            mediaAssetId: $asset->id,
                        ));
                    }

                    if (! $this->storage->exists(new MediaLocation($asset->disk, $asset->path))) {
                        $missingMasters++;
                        $onIssue(new MediaAuditIssueData(
                            issueType: MediaAuditIssueType::MissingMasterFile,
                            mediaAssetId: $asset->id,
                            disk: $asset->disk,
                            path: $asset->path,
                        ));
                    }

                    foreach ($asset->variants as $variant) {
                        if (! $this->storage->exists(new MediaLocation($variant->disk, $variant->path))) {
                            $missingVariantFiles++;
                            $onIssue(new MediaAuditIssueData(
                                issueType: MediaAuditIssueType::MissingVariantFile,
                                mediaAssetId: $asset->id,
                                mediaVariantId: $variant->id,
                                disk: $variant->disk,
                                path: $variant->path,
                            ));
                        }
                    }
                }
            });

        $orphans = $this->orphanScanner->scan();

        foreach ($orphans as $location) {
            $onIssue(new MediaAuditIssueData(
                issueType: MediaAuditIssueType::PhysicalOrphanCandidate,
                disk: $location->disk,
                path: $location->path,
            ));
        }

        // recentMediaJobFailures() is capped (see FailedMediaJobReader) —
        // deliberately a diagnostics panel, not a full queue browser — so
        // MediaAuditSummary.failedMediaJobs (from the uncapped count below)
        // can be larger than the number of failed_generation_job issues
        // actually persisted if there are more than the cap.
        $failedJobs = $this->failedJobReader->recentMediaJobFailures();

        foreach ($failedJobs as $failedJob) {
            $onIssue(new MediaAuditIssueData(
                issueType: MediaAuditIssueType::FailedGenerationJob,
                mediaAssetId: $failedJob->mediaAssetId,
                context: [
                    'job_class' => $failedJob->jobClass,
                    'uuid' => $failedJob->uuid,
                    'failed_at' => $failedJob->failedAt->toIso8601String(),
                    'exception_summary' => $failedJob->exceptionSummary,
                ],
            ));
        }

        return new MediaAuditSummary(
            assetsChecked: $assetsChecked,
            variantsChecked: $variantsChecked,
            healthyAssets: $healthyAssets,
            activeUnreferencedAssets: $activeUnreferencedAssets,
            softDeletedWithinGrace: $softDeletedWithinGrace,
            purgeableAssets: $purgeableAssets,
            missingMasters: $missingMasters,
            missingVariantFiles: $missingVariantFiles,
            physicalOrphanCandidates: count($orphans),
            failedMediaJobs: $this->failedJobReader->countMediaJobFailures(),
        );
    }
}
