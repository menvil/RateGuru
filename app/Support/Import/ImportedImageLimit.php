<?php

namespace App\Support\Import;

use App\Services\Media\ImageIngestPolicy;

/**
 * The most bytes an imported image may have: the import's own cap, or less
 * when the image ingest takes less — a larger import cap would fetch a
 * payload the ingest then refuses, wasting the bandwidth and time the cap
 * exists to save. The preview checks against the same number as the
 * download, so an image the preview offers is one the download takes.
 */
final class ImportedImageLimit
{
    public static function bytes(): int
    {
        return min(
            (int) config('import.max_image_bytes', 8 * 1024 * 1024),
            ImageIngestPolicy::fromConfig()->maxBytes,
        );
    }
}
