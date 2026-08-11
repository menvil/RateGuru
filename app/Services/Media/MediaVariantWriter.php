<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use Throwable;

/**
 * Generates one variant and persists it (file + row). No atomic move
 * primitive exists on MediaStorage, so ordering is best-effort, not
 * transactional: generate/encode/validate fully in memory first (the
 * failure-prone part), then write the file, then upsert the row.
 *
 * On a first-time write (no prior row for this asset+name), a DB failure
 * after the file write deletes the now-orphaned file. On a regeneration (a
 * row already existed), a DB failure after the overwrite does *not* delete
 * the file — the previously-working file has already been replaced, so
 * deleting it would leave nothing; the row's metadata may be transiently
 * stale until a successful retry, which is preferred over destroying a
 * working variant.
 */
final class MediaVariantWriter
{
    public function __construct(
        private readonly ImageVariantProcessor $processor,
        private readonly MediaVariantPathGenerator $pathGenerator,
        private readonly MediaStorage $storage,
    ) {}

    public function write(MediaAsset $asset, string $masterBytes, MediaVariantSpecification $specification): MediaVariant
    {
        $generated = $this->processor->generate($masterBytes, $asset->mime_type, $specification);

        $path = $this->pathGenerator->generate($asset, $specification->name, $generated->extension);
        $location = new MediaLocation($asset->disk, $path);

        $isFirstWrite = ! MediaVariant::query()
            ->where('media_asset_id', $asset->id)
            ->where('name', $specification->name)
            ->exists();

        $this->storage->putContents($location, $generated->bytes, $asset->visibility);

        try {
            return MediaVariant::query()->updateOrCreate(
                ['media_asset_id' => $asset->id, 'name' => $specification->name],
                [
                    'disk' => $asset->disk,
                    'path' => $path,
                    'mime_type' => $generated->mimeType,
                    'extension' => $generated->extension,
                    'byte_size' => $generated->byteSize,
                    'width' => $generated->width,
                    'height' => $generated->height,
                ],
            );
        } catch (Throwable $exception) {
            if ($isFirstWrite) {
                $this->deleteQuietly($location);
            }

            throw $exception;
        }
    }

    /**
     * Best-effort, same shape as StoredMediaCleaner::deleteIfUnclaimed():
     * skips deletion when another MediaVariant row now claims this exact
     * (disk, path) — a concurrent retry for the same asset+spec could have
     * written and committed its own row in the window between this attempt's
     * file write and its failing upsert, and deleting the file then would
     * orphan that other, successful row instead of just this failed one.
     */
    private function deleteQuietly(MediaLocation $location): void
    {
        try {
            $claimed = MediaVariant::query()
                ->where('disk', $location->disk)
                ->where('path', $location->path)
                ->exists();

            if ($claimed) {
                return;
            }

            $this->storage->delete($location);
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }
}
