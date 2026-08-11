<?php

namespace App\Services\Media;

use App\Enums\MediaStatus;
use App\Enums\MediaVariantName;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    public function generateAll(MediaAsset $asset, ?MediaVariantName $only = null): void
    {
        if ($asset->trashed() || $asset->status !== MediaStatus::Ready) {
            return;
        }

        if (! in_array($asset->mime_type, self::RASTER_MIME_TYPES, true)) {
            return;
        }

        $masterBytes = $this->readMasterBytes($asset);

        foreach ($this->registry->for($asset->kind) as $specification) {
            if ($only !== null && $specification->name !== $only) {
                continue;
            }

            // Cover/CoverSquare specs are skipped when the source is too
            // small to crop-to-fill without upscaling — Contain specs always
            // generate, capped at the source's own size when smaller than
            // the bounds, never upscaled.
            if ($specification->wouldUpscale($asset->width, $asset->height)) {
                continue;
            }

            try {
                $this->writer->write($asset, $masterBytes, $specification);
            } catch (Throwable $exception) {
                Log::error('MediaVariantGenerator: variant generation failed.', [
                    'media_asset_id' => $asset->id,
                    'variant' => $specification->name->value,
                    'exception_class' => $exception::class,
                ]);

                throw $exception;
            }
        }
    }

    private function readMasterBytes(MediaAsset $asset): string
    {
        try {
            $stream = $this->storage->readStream(new MediaLocation($asset->disk, $asset->path));
        } catch (Throwable $exception) {
            Log::error('MediaVariantGenerator: master image missing or unreadable.', [
                'media_asset_id' => $asset->id,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        try {
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }
}
