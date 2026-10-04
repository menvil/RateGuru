<?php

namespace App\Console\Commands;

use App\Enums\MediaPurgeOutcome;
use App\Models\MediaAsset;
use App\Services\Media\MediaLifecycleService;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaOrphanScanner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Physically deletes media that MediaLifecycleService says is safe to
 * delete. Two independent modes, never mixed in one run:
 *
 * - Default: soft-deleted, grace-expired, unreferenced MediaAsset rows
 *   (and their variants/files) — real and destructive by default, since
 *   eligibility itself (re-verified under lock by
 *   MediaLifecycleService::purge()) is the safety gate.
 * - --orphans: physical files with no matching DB row at all. Report-only
 *   unless --force is also given — orphan deletion is never the default,
 *   matching the task's own explicit "only --orphans --force" rule.
 *
 * --dry-run always wins over --force if both are somehow passed together.
 */
final class MediaPurgeCommand extends Command
{
    private const int MAX_CHUNK_SIZE = 1000;

    private const int MAX_ORPHANS_LISTED = 50;

    protected $signature = 'media:purge
        {--asset= : Only process the MediaAsset with this id}
        {--older-than= : Override the configured grace period, in days, for this run}
        {--dry-run : Report what would be purged without deleting anything}
        {--orphans : Purge physical files with no matching DB row instead of soft-deleted assets}
        {--force : Required alongside --orphans to actually delete physical orphan files}
        {--chunk=200 : Number of assets to load per chunk}';

    protected $description = 'Physically delete media that is safe to delete: grace-expired soft-deleted assets, or (with --orphans --force) unreferenced physical files.';

    public function handle(MediaLifecycleService $lifecycle, MediaOrphanScanner $orphanScanner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $graceDays = $this->graceDaysOverride();

        if ($graceDays === false) {
            return self::FAILURE;
        }

        if ((bool) $this->option('orphans')) {
            return $this->purgeOrphans($orphanScanner, $lifecycle, $dryRun, $force);
        }

        return $this->purgeAssets($lifecycle, $dryRun, $graceDays);
    }

    private function purgeAssets(MediaLifecycleService $lifecycle, bool $dryRun, ?int $graceDays): int
    {
        $cutoff = now()->subDays($graceDays ?? (int) config('media.lifecycle.purge_grace_days'));

        $query = MediaAsset::onlyTrashed()->where('deleted_at', '<=', $cutoff);

        if (! $this->applyAssetFilter($query)) {
            return self::FAILURE;
        }

        $chunkSize = $this->chunkSize();
        $total = (clone $query)->count();
        $progress = $total > 0 ? $this->output->createProgressBar($total) : null;
        $progress?->start();

        $counts = [];

        $query->orderBy('id')->chunkById($chunkSize, function ($assets) use (&$counts, $lifecycle, $dryRun, $graceDays, $progress): void {
            foreach ($assets as $asset) {
                $progress?->advance();

                if ($dryRun) {
                    $key = $lifecycle->isPurgeable($asset, $graceDays) ? 'would_purge' : 'not_eligible';
                    $counts[$key] = ($counts[$key] ?? 0) + 1;

                    continue;
                }

                $result = $lifecycle->purge($asset, $graceDays);
                $counts[$result->outcome->value] = ($counts[$result->outcome->value] ?? 0) + 1;

                if ($result->outcome === MediaPurgeOutcome::Failed) {
                    $this->error("Failed to purge media asset {$asset->id}: ".($result->exception?->getMessage() ?? 'unknown error'));
                }
            }
        });

        if ($progress !== null) {
            $progress->finish();
            $this->newLine();
        }

        if ($dryRun) {
            $wouldPurge = $counts['would_purge'] ?? 0;
            $this->info("Would purge {$wouldPurge} of {$total} candidate media assets (dry run — nothing changed).");

            return self::SUCCESS;
        }

        $purged = $counts[MediaPurgeOutcome::Purged->value] ?? 0;
        $failed = $counts[MediaPurgeOutcome::Failed->value] ?? 0;

        $this->info("Purged {$purged} of {$total} candidate media assets.");

        foreach ([MediaPurgeOutcome::NotEligible, MediaPurgeOutcome::Locked, MediaPurgeOutcome::AlreadyGone] as $skipOutcome) {
            if (($counts[$skipOutcome->value] ?? 0) > 0) {
                $this->line("  Skipped ({$skipOutcome->value}): {$counts[$skipOutcome->value]}");
            }
        }

        if ($failed > 0) {
            $this->error("Failed to purge {$failed} media assets.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function purgeOrphans(MediaOrphanScanner $orphanScanner, MediaLifecycleService $lifecycle, bool $dryRun, bool $force): int
    {
        $orphans = $orphanScanner->scan();
        $reportOnly = $dryRun || ! $force;

        if ($reportOnly) {
            $this->info(count($orphans).' physical orphan file(s) found'.($force ? ' (dry run — nothing changed)' : ', pass --orphans --force to delete them').'.');
            $this->listOrphans($orphans);

            return self::SUCCESS;
        }

        $this->listOrphans($orphans);

        $deleted = 0;
        $failed = 0;

        foreach ($orphans as $location) {
            try {
                $lifecycle->purgeOrphanFile($location);
                $deleted++;
            } catch (Throwable $exception) {
                $failed++;

                Log::error('MediaPurgeCommand: failed to delete a physical orphan file.', [
                    'disk' => $location->disk,
                    'path' => $location->path,
                    'operation' => 'purge_orphan_file',
                    'exception_class' => $exception::class,
                ]);

                $this->error("Failed to delete orphan {$location->disk}:{$location->path}: {$exception->getMessage()}");
            }
        }

        $this->info("Deleted {$deleted} of ".count($orphans).' physical orphan file(s).');

        if ($failed > 0) {
            $this->error("Failed to delete {$failed} physical orphan file(s).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<MediaLocation>  $orphans
     */
    private function listOrphans(array $orphans): void
    {
        foreach (array_slice($orphans, 0, self::MAX_ORPHANS_LISTED) as $location) {
            $this->line("  {$location->disk}:{$location->path}");
        }

        $remaining = count($orphans) - self::MAX_ORPHANS_LISTED;

        if ($remaining > 0) {
            $this->line("  ... and {$remaining} more.");
        }
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    private function applyAssetFilter(Builder $query): bool
    {
        $rawAssetId = $this->option('asset');

        if ($rawAssetId === null) {
            return true;
        }

        $assetId = filter_var($rawAssetId, FILTER_VALIDATE_INT);

        if ($assetId === false) {
            $this->error("Invalid --asset value: [{$rawAssetId}].");

            return false;
        }

        $query->whereKey($assetId);

        return true;
    }

    /**
     * @return int|null|false null means "use the configured default", false
     *                        signals an invalid option value (already
     *                        reported to the user)
     */
    private function graceDaysOverride(): int|null|false
    {
        $raw = $this->option('older-than');

        if ($raw === null) {
            return null;
        }

        $days = filter_var($raw, FILTER_VALIDATE_INT);

        if ($days === false || $days < 0) {
            $this->error("Invalid --older-than value: [{$raw}].");

            return false;
        }

        return $days;
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
