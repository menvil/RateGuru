<?php

namespace App\Services\Media;

use App\Enums\MediaStatus;
use App\Enums\MediaVariantName;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\Exceptions\MediaStorageException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates every *missing* variant for one MediaAsset by default — a spec
 * whose row and physical file both already exist is left untouched. Plain
 * service (not a job) so both GenerateMediaVariantsJob and demo seeders can
 * call the same logic — seeders synchronously, real uploads via the queued
 * job. For a brand-new asset (the common case for both of those call sites)
 * nothing exists yet, so "missing" is simply "everything", making this the
 * same as full generation for them at the cost of one cheap extra query.
 * Pass $force to bypass the skip-if-already-generated check and rewrite
 * every applicable spec regardless (used by `media:generate-variants
 * --force`). A failure on any one spec propagates immediately rather than
 * being caught and skipped: updateOrCreate() makes redoing already-succeeded
 * specs on retry a safe, idempotent no-op, so failing the whole call is a
 * simple, deliberate tradeoff over partial-success bookkeeping.
 */
final class MediaVariantGenerator
{
    private const array RASTER_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly MediaVariantSpecificationRegistry $registry,
        private readonly MediaVariantWriter $writer,
        private readonly MediaStorage $storage,
    ) {}

    public function generateAll(MediaAsset $asset, ?MediaVariantName $only = null, bool $force = false): void
    {
        if ($asset->trashed() || $asset->status !== MediaStatus::Ready) {
            return;
        }

        if (! in_array($asset->mime_type, self::RASTER_MIME_TYPES, true)) {
            return;
        }

        $existingByName = $asset->relationLoaded('variants')
            ? $asset->variants
            : MediaVariant::query()->where('media_asset_id', $asset->id)->get();
        $existingByName = $existingByName->keyBy(fn (MediaVariant $variant): string => $variant->name->value);

        $specifications = [];

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

            if (! $force && $this->isAlreadyGenerated($existingByName, $specification)) {
                continue;
            }

            $specifications[] = $specification;
        }

        if ($specifications === []) {
            return;
        }

        // Deferred until here — opening and reading the (potentially large)
        // master image is wasted I/O when every applicable spec already
        // exists and is valid, which is the common case for a recovery
        // command run repeatedly.
        $masterBytes = $this->readMasterBytes($asset);

        foreach ($specifications as $specification) {
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

    /**
     * @param  Collection<string, MediaVariant>  $existingByName
     */
    private function isAlreadyGenerated(Collection $existingByName, MediaVariantSpecification $specification): bool
    {
        $existing = $existingByName->get($specification->name->value);

        if ($existing === null) {
            return false;
        }

        return $this->storage->exists(new MediaLocation($existing->disk, $existing->path));
    }

    private function readMasterBytes(MediaAsset $asset): string
    {
        try {
            $stream = $this->storage->readStream(new MediaLocation($asset->disk, $asset->path));

            try {
                $contents = stream_get_contents($stream);
            } finally {
                fclose($stream);
            }

            if ($contents === false) {
                throw MediaStorageException::couldNotReadStream($asset->disk, $asset->path);
            }

            return $contents;
        } catch (Throwable $exception) {
            Log::error('MediaVariantGenerator: master image missing or unreadable.', [
                'media_asset_id' => $asset->id,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
