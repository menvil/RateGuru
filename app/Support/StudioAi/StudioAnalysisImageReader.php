<?php

namespace App\Support\StudioAi;

use App\Enums\MediaVariantName;
use App\Enums\StudioImageAnalysisError;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaStorage;
use finfo;
use Throwable;

/**
 * Reads a stored photo to be analysed — only ever an asset the media library
 * holds, by its disk and path through MediaStorage: never an arbitrary path,
 * never a URL, and nothing downloaded.
 *
 * The ~1280 px feed variant is read when the photo has one — plenty for a
 * description, and a fraction of the bytes — and the normalized master
 * otherwise; nothing is resized or re-encoded here. At most
 * content_studio.image_analysis.max_image_bytes are read, the bytes must be a
 * JPEG, PNG or WebP by their own signature, and the stream is closed however
 * the read ends. Nothing is written anywhere: the bytes live in memory for the
 * one request.
 */
final class StudioAnalysisImageReader
{
    private const SUPPORTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly MediaStorage $storage) {}

    /**
     * @throws StudioImageAnalysisFailedException ImageMissing when there is no such photo, ImageUnavailable when it cannot be read or sent
     */
    public function read(int $assetId): StudioAnalysisImage
    {
        $asset = MediaAsset::query()->find($assetId);

        if ($asset === null) {
            throw new StudioImageAnalysisFailedException(StudioImageAnalysisError::ImageMissing);
        }

        $variant = MediaVariant::query()
            ->where('media_asset_id', $asset->id)
            ->where('name', MediaVariantName::PostFeed1280)
            ->first();

        // The variant when it can be read; the master when it cannot, or
        // when there is none — a photo whose variants are still being made.
        if ($variant !== null) {
            $image = $this->attempt($variant->disk, $variant->path, $variant->width, $variant->height);

            if ($image !== null) {
                return $image;
            }
        }

        return $this->attempt($asset->disk, $asset->path, $asset->width, $asset->height)
            ?? throw new StudioImageAnalysisFailedException(StudioImageAnalysisError::ImageUnavailable);
    }

    private function attempt(string $disk, string $path, ?int $width, ?int $height): ?StudioAnalysisImage
    {
        $bytes = $this->bytesAt($disk, $path);

        if ($bytes === null || $bytes === '') {
            return null;
        }

        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($type) && in_array($type, self::SUPPORTED_TYPES, true)
            ? new StudioAnalysisImage($bytes, $type, $width, $height)
            : null;
    }

    /** The file's bytes, or null when it is missing, unreadable or larger than may be sent. */
    private function bytesAt(string $disk, string $path): ?string
    {
        $limit = $this->maxBytes();

        try {
            // A location that is not one — a URL, say — is refused here too.
            $stream = $this->storage->readStream(new MediaLocation($disk, $path));
        } catch (Throwable) {
            return null;
        }

        try {
            // One byte past the limit tells a file at the limit from a larger one.
            $bytes = stream_get_contents($stream, $limit + 1);
        } catch (Throwable) {
            $bytes = false;
        } finally {
            fclose($stream);
        }

        return is_string($bytes) && strlen($bytes) <= $limit ? $bytes : null;
    }

    private function maxBytes(): int
    {
        $bytes = config('content_studio.image_analysis.max_image_bytes');

        return is_int($bytes) && $bytes > 0 ? $bytes : 8 * 1024 * 1024;
    }
}
