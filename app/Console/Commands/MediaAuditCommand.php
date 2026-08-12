<?php

namespace App\Console\Commands;

use App\Services\Media\MediaAuditService;
use Illuminate\Console\Command;

/**
 * Thin console adapter over MediaAuditService — all audit logic lives there
 * (shared with RunMediaAuditJob and the Filament diagnostics page, which
 * dispatches that job rather than running an audit inline). Read-only:
 * never mutates anything. Always exits SUCCESS: finding problems is this
 * command's whole job, not a failure.
 */
final class MediaAuditCommand extends Command
{
    private const int MAX_CHUNK_SIZE = 1000;

    protected $signature = 'media:audit
        {--chunk=200 : Number of assets to load per chunk}';

    protected $description = 'Report media lifecycle state (referenced/orphaned/purgeable/missing files) without changing anything.';

    public function handle(MediaAuditService $auditService): int
    {
        $this->info('Running full media audit — this scans every asset/variant and the physical disk, and may take a while on a large library.');

        // No per-asset progress bar here: MediaAuditService::run() only
        // reports issues as they're found (via $onIssue), not "asset N of
        // M processed" — a real per-asset signal would mean adding a second
        // callback to the shared service purely for this command's cosmetic
        // benefit, which isn't worth the extra surface area.
        $summary = $auditService->run(chunkSize: $this->chunkSize());

        $this->newLine();
        $this->info('Media asset classification:');
        $this->line("  Healthy, referenced: {$summary->healthyAssets}");
        $this->line("  Active, but unreferenced (unexpected — investigate): {$summary->activeUnreferencedAssets}");
        $this->line("  Soft-deleted, within grace period: {$summary->softDeletedWithinGrace}");
        $this->line("  Soft-deleted, purgeable now: {$summary->purgeableAssets}");
        $this->newLine();
        $this->info('DB/filesystem divergence:');
        $this->line("  Assets with a missing master file: {$summary->missingMasters}");
        $this->line("  Variants with a missing physical file: {$summary->missingVariantFiles}");
        $this->newLine();
        $this->info('Physical orphans (no matching DB row, older than the orphan grace period):');
        $this->line("  Candidate files: {$summary->physicalOrphanCandidates}");
        $this->newLine();
        $this->info('Failed media jobs:');
        $this->line("  Failures: {$summary->failedMediaJobs}");

        if ($summary->purgeableAssets > 0) {
            $this->newLine();
            $this->comment('Run `php artisan media:purge --dry-run` to preview purging soft-deleted, unreferenced assets.');
        }

        if ($summary->physicalOrphanCandidates > 0) {
            $this->comment('Run `php artisan media:purge --orphans --dry-run` to preview physical-orphan candidates.');
        }

        return self::SUCCESS;
    }

    private function chunkSize(): int
    {
        $rawChunkSize = $this->option('chunk');
        $chunkSize = filter_var($rawChunkSize, FILTER_VALIDATE_INT);

        if ($chunkSize === false || $chunkSize < 1) {
            $this->warn('Invalid chunk size provided; using 1.');

            return 1;
        }

        if ($chunkSize > self::MAX_CHUNK_SIZE) {
            $this->warn('Chunk size exceeds maximum; using '.self::MAX_CHUNK_SIZE.'.');

            return self::MAX_CHUNK_SIZE;
        }

        return $chunkSize;
    }
}
