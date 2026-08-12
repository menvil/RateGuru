<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use App\Services\Media\MediaLifecycleService;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaOrphanScanner;
use App\Services\Media\MediaReferenceChecker;
use App\Services\Media\MediaStorage;
use Illuminate\Console\Command;

/**
 * Read-only report of every MediaAsset/MediaVariant's lifecycle state and
 * any DB/filesystem divergence — never mutates anything. Always exits
 * SUCCESS: finding problems is this command's whole job, not a failure.
 */
final class MediaAuditCommand extends Command
{
    private const int MAX_CHUNK_SIZE = 1000;

    protected $signature = 'media:audit
        {--chunk=200 : Number of assets to load per chunk}';

    protected $description = 'Report media lifecycle state (referenced/orphaned/purgeable/missing files) without changing anything.';

    public function handle(
        MediaReferenceChecker $referenceChecker,
        MediaLifecycleService $lifecycle,
        MediaOrphanScanner $orphanScanner,
        MediaStorage $storage,
    ): int {
        $counts = [
            'healthy_referenced' => 0,
            'active_unreferenced' => 0,
            'soft_deleted_within_grace' => 0,
            'soft_deleted_purgeable' => 0,
            'missing_master' => 0,
            'variant_missing_file' => 0,
        ];

        $totalAssets = MediaAsset::withTrashed()->count();
        $progress = $totalAssets > 0 ? $this->output->createProgressBar($totalAssets) : null;
        $progress?->start();

        MediaAsset::withTrashed()->with('variants')->orderBy('id')
            ->chunkById($this->chunkSize(), function ($assets) use (&$counts, $referenceChecker, $lifecycle, $storage, $progress): void {
                // Two queries per chunk (via referencedAssetIds()), not two
                // queries per asset: MediaReferenceChecker::isReferenced()
                // is fine for the single-asset, lock-guarded checks purge()
                // does, but calling it in this loop would mean 2*N queries
                // for an audit run scanning the whole table.
                $referencedIds = $referenceChecker->referencedAssetIds($assets->pluck('id'));

                foreach ($assets as $asset) {
                    $progress?->advance();

                    $isReferenced = $referencedIds->has($asset->id);

                    if ($asset->trashed()) {
                        $counts[$lifecycle->isGraceExpired($asset) && ! $isReferenced ? 'soft_deleted_purgeable' : 'soft_deleted_within_grace']++;
                    } else {
                        $counts[$isReferenced ? 'healthy_referenced' : 'active_unreferenced']++;
                    }

                    if (! $storage->exists(new MediaLocation($asset->disk, $asset->path))) {
                        $counts['missing_master']++;
                    }

                    foreach ($asset->variants as $variant) {
                        if (! $storage->exists(new MediaLocation($variant->disk, $variant->path))) {
                            $counts['variant_missing_file']++;
                        }
                    }
                }
            });

        if ($progress !== null) {
            $progress->finish();
            $this->newLine();
        }

        $this->newLine();
        $this->info('Media asset classification:');
        $this->line("  Healthy, referenced: {$counts['healthy_referenced']}");
        $this->line("  Active, but unreferenced (unexpected — investigate): {$counts['active_unreferenced']}");
        $this->line("  Soft-deleted, within grace period: {$counts['soft_deleted_within_grace']}");
        $this->line("  Soft-deleted, purgeable now: {$counts['soft_deleted_purgeable']}");
        $this->newLine();
        $this->info('DB/filesystem divergence:');
        $this->line("  Assets with a missing master file: {$counts['missing_master']}");
        $this->line("  Variants with a missing physical file: {$counts['variant_missing_file']}");

        $orphans = $orphanScanner->scan();
        $this->newLine();
        $this->info('Physical orphans (no matching DB row, older than the orphan grace period):');
        $this->line('  Candidate files: '.count($orphans));

        if ($counts['soft_deleted_purgeable'] > 0) {
            $this->newLine();
            $this->comment('Run `php artisan media:purge --dry-run` to preview purging soft-deleted, unreferenced assets.');
        }

        if (count($orphans) > 0) {
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
