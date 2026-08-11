<?php

namespace App\Services\Media;

use App\Enums\MediaResizeMode;
use App\Enums\MediaStatus;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Log;

/**
 * Generates every applicable variant for one MediaAsset. Plain service (not
 * a job) so both GenerateMediaVariantsJob and demo seeders can call the same
 * logic — seeders synchronously, real uploads via the queued job. A failure
 * on any one spec propagates immediately rather than being caught and
 * skipped: updateOrCreate() makes redoing already-succeeded specs on retry a
 * safe, idempotent no-op, so failing the whole call is a simple, deliberate
 * tradeoff over partial-success bookkeeping.
 */
final class MediaVariantGenerator
{
    private const array RASTER_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly MediaVariantSpecificationRegistry $registry,
        private readonly MediaVariantWriter $writer,
        private readonly MediaStorage $storage,
    ) {}

    public function generateAll(MediaAsset $asset): void
    {
        if ($asset->trashed() || $asset->status !== MediaStatus::Ready) {
            return;
        }

        if (! in_array($asset->mime_type, self::RASTER_MIME_TYPES, true)) {
            return;
        }

        $masterBytes = $this->readMasterBytes($asset);

        foreach ($this->registry->for($asset->kind) as $specification) {
            // Only CoverSquare specs are ever skipped for being too small —
            // Contain specs always generate, capped at the source's own size
            // when smaller than the bounds, never upscaled.
            if ($specification->mode === MediaResizeMode::CoverSquare && min($asset->width, $asset->height) < $specification->maxWidth) {
                continue;
            }

            $this->writer->write($asset, $masterBytes, $specification);

            Log::info('Generated media variant.', [
                'media_asset_id' => $asset->id,
                'variant' => $specification->name->value,
            ]);
        }
    }

    private function readMasterBytes(MediaAsset $asset): string
    {
        $stream = $this->storage->readStream(new MediaLocation($asset->disk, $asset->path));

        try {
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }
}
