<?php

namespace App\Services\Images;

use App\Models\MediaAsset;
use Throwable;

final class StoredMediaCleaner
{
    public function __construct(
        private readonly ImageStorage $imageStorage,
    ) {}

    /**
     * Best-effort compensation for a database failure that happens after a
     * file has already been written. Best-effort: a cleanup failure is
     * reported but never replaces (or suppresses) the original exception
     * that triggered it.
     *
     * Skips deletion when another MediaAsset already legitimately owns this
     * exact (disk, path) — otherwise, on the rare path collision, cleaning
     * up after this failed upload would delete a file a different,
     * successfully-committed asset now depends on.
     */
    public function deleteIfUnclaimed(StoredMedia $media): void
    {
        $claimed = MediaAsset::query()
            ->where(['disk' => $media->disk, 'path' => $media->path])
            ->exists();

        if ($claimed) {
            return;
        }

        try {
            $this->imageStorage->delete($media);
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }
}
