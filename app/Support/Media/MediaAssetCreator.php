<?php

namespace App\Support\Media;

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\MediaPathGenerator;
use App\Services\Media\MediaStoreRequest;
use App\Services\Media\StoredMedia;
use App\Services\Media\StoredVideo;

/**
 * Builds the MediaAsset row for a just-stored file. Shared by post-image and
 * avatar uploads so both stay consistent — not a storage/URL abstraction,
 * just the one piece of asset-construction logic they both need.
 */
final class MediaAssetCreator
{
    public function __construct(
        private readonly ImageOrientationClassifier $orientationClassifier,
    ) {}

    public function create(StoredMedia $stored, MediaKind $kind, ?User $owner): MediaAsset
    {
        $hasValidDimensions = $stored->width !== null
            && $stored->height !== null
            && $stored->width > 0
            && $stored->height > 0;

        return MediaAsset::create([
            'owner_user_id' => $owner?->id,
            'kind' => $kind,
            // Every file stored through here passed the image ingest; a
            // still image has no duration and no sound.
            'media_type' => MediaType::Image,
            'duration_ms' => null,
            'has_audio' => false,
            'disk' => $stored->disk,
            'path' => $stored->path,
            'original_filename' => $stored->originalFilename,
            'mime_type' => $stored->mimeType,
            'extension' => $stored->extension,
            'byte_size' => $stored->byteSize,
            'width' => $stored->width,
            'height' => $stored->height,
            'aspect_ratio' => $hasValidDimensions
                ? round($stored->width / $stored->height, 6)
                : null,
            'orientation' => $hasValidDimensions
                ? $this->orientationClassifier->classify($stored->width, $stored->height)
                : null,
            'status' => MediaStatus::Ready,
            'visibility' => MediaVisibility::Public,
        ]);
    }

    /**
     * The asset of an accepted video or animated GIF: it points at the
     * private original and waits in Processing. Its dimensions, length and
     * sound are the probe's until the master replaces them.
     */
    public function createProcessing(StoredVideo $video, User $owner): MediaAsset
    {
        $width = $video->probe->width;
        $height = $video->probe->height;
        $hasValidDimensions = $width > 0 && $height > 0;

        return MediaAsset::create([
            'owner_user_id' => $owner->id,
            'kind' => MediaKind::PostImage,
            'media_type' => $video->mediaType,
            'duration_ms' => $video->probe->durationMs,
            // An animation plays silent whatever its file carries.
            'has_audio' => $video->mediaType === MediaType::Video && $video->probe->hasAudio(),
            'disk' => $video->media->disk,
            'path' => $video->media->path,
            'original_filename' => $video->media->originalFilename,
            'mime_type' => $video->media->mimeType,
            'extension' => $video->media->extension,
            'byte_size' => $video->media->byteSize,
            'width' => $hasValidDimensions ? $width : null,
            'height' => $hasValidDimensions ? $height : null,
            'aspect_ratio' => $hasValidDimensions ? round($width / $height, 6) : null,
            'orientation' => $hasValidDimensions ? $this->orientationClassifier->classify($width, $height) : null,
            'status' => MediaStatus::Processing,
            'visibility' => MediaVisibility::Private,
        ]);
    }

    /**
     * The asset of a video or GIF to be downloaded from $url: it waits in
     * Processing, private, at the place its original will be stored, until
     * ImportVideoAssetJob has fetched the file, and then as any upload.
     */
    public function createAwaitingDownload(User $owner, string $url): MediaAsset
    {
        $request = MediaStoreRequest::forVideoOriginal((int) $owner->id);

        return MediaAsset::create([
            'owner_user_id' => $owner->id,
            'kind' => MediaKind::PostImage,
            'media_type' => MediaType::Video,
            'duration_ms' => null,
            'has_audio' => false,
            'disk' => $request->disk,
            'path' => app(MediaPathGenerator::class)->generate($request, 'download'),
            'original_filename' => mb_substr(basename((string) parse_url($url, PHP_URL_PATH)) ?: 'video', 0, 255),
            'mime_type' => 'application/octet-stream',
            'extension' => null,
            'byte_size' => 0,
            'status' => MediaStatus::Processing,
            'visibility' => MediaVisibility::Private,
            'metadata' => ['import' => ['url' => $url]],
        ]);
    }
}
