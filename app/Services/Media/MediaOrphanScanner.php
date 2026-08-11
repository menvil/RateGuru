<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaVariant;

/**
 * Finds physical files with no matching MediaAsset/MediaVariant row at all —
 * distinct from (and scanned separately from) "a row exists but its file is
 * missing", which media:generate-variants --missing-only and
 * MediaLifecycleService::purge() already handle on the DB-driven side.
 * Read-only: this class only ever reports candidates, never deletes
 * anything — deletion is a separate, explicit opt-in the caller performs
 * (MediaLifecycleService::purgeOrphanFile()).
 *
 * Scans config('media.disks.public') — the only disk this app's config
 * declares today, not generalized to a hypothetical multi-disk future.
 */
final class MediaOrphanScanner
{
    public function __construct(
        private readonly MediaStorage $storage,
    ) {}

    /**
     * @return list<MediaLocation> physical files with no matching DB row,
     *                             older than config('media.lifecycle.orphan_grace_hours')
     *                             — an in-flight upload from moments ago is
     *                             never flagged.
     */
    public function scan(): array
    {
        $known = $this->knownLocations();
        $disk = (string) config('media.disks.public');
        $cutoff = now()->subHours((int) config('media.lifecycle.orphan_grace_hours'))->getTimestamp();

        $candidates = [];

        foreach (config('media.directories') as $directory) {
            foreach ($this->storage->allFiles($disk, $directory) as $path) {
                if (isset($known["{$disk}\0{$path}"])) {
                    continue;
                }

                $location = new MediaLocation($disk, $path);

                if ($this->storage->lastModified($location) <= $cutoff) {
                    $candidates[] = $location;
                }
            }
        }

        return $candidates;
    }

    /**
     * @return array<string, true> a set keyed by "disk\0path", including
     *                             soft-deleted-but-still-within-grace
     *                             assets — those legitimately still own
     *                             their file and must never be
     *                             misclassified as orphans.
     */
    private function knownLocations(): array
    {
        $known = [];

        MediaAsset::withTrashed()->select('disk', 'path')
            ->chunk(500, function ($rows) use (&$known): void {
                foreach ($rows as $row) {
                    $known["{$row->disk}\0{$row->path}"] = true;
                }
            });

        MediaVariant::query()->select('disk', 'path')
            ->chunk(500, function ($rows) use (&$known): void {
                foreach ($rows as $row) {
                    $known["{$row->disk}\0{$row->path}"] = true;
                }
            });

        return $known;
    }
}
