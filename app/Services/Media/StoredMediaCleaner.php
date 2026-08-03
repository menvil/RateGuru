<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Throwable;

final class StoredMediaCleaner
{
    public function __construct(
        private readonly MediaStorage $mediaStorage,
    ) {}

    /**
     * Best-effort compensation for a database failure that happens after a
     * file has already been written. Best-effort: a cleanup failure — in
     * either the ownership check or the delete itself — is reported but
     * never replaces (or suppresses) the original exception that triggered
     * it, since a DB-connectivity failure (the scenario this exists for) is
     * exactly the kind of failure the ownership query can also hit.
     *
     * Skips deletion when another MediaAsset — active or soft-deleted —
     * already legitimately owns this exact (disk, path). A soft-deleted
     * avatar asset deliberately keeps its physical file on disk (orphan
     * cleanup is deferred to PR-07), so excluding trashed rows here could
     * delete that retained file out from under it on a path collision.
     */
    public function deleteIfUnclaimed(StoredMedia $media): void
    {
        try {
            $claimed = MediaAsset::withTrashed()
                ->where(['disk' => $media->disk, 'path' => $media->path])
                ->exists();

            if ($claimed) {
                return;
            }

            $this->mediaStorage->delete($media->location());
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }
}
