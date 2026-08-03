<?php

namespace App\Services\Media;

use App\Enums\MediaVisibility;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Services\Media\Exceptions\MediaIsNotPublicException;
use App\Services\Media\Exceptions\MediaUrlResolutionException;
use Illuminate\Filesystem\FilesystemManager;
use Throwable;

final class FilesystemMediaUrlResolver implements MediaUrlResolver
{
    public function __construct(
        private readonly FilesystemManager $filesystem,
    ) {}

    public function publicUrl(MediaAsset|MediaVariant $media): string
    {
        if ($this->visibilityOf($media) !== MediaVisibility::Public) {
            throw MediaIsNotPublicException::forMedia($media);
        }

        return $this->resolveUrl($media->disk, $media->path);
    }

    public function publicUrlOrNull(MediaAsset|MediaVariant|null $media): ?string
    {
        if ($media === null || $this->visibilityOf($media) !== MediaVisibility::Public) {
            return null;
        }

        return $this->resolveUrl($media->disk, $media->path);
    }

    /**
     * MediaVariant has no visibility of its own — it inherits its parent
     * asset's, since a variant is never more permissive than the file it was
     * derived from.
     */
    private function visibilityOf(MediaAsset|MediaVariant $media): MediaVisibility
    {
        return $media instanceof MediaAsset ? $media->visibility : $media->asset->visibility;
    }

    private function resolveUrl(string $disk, string $path): string
    {
        try {
            return $this->filesystem->disk($disk)->url($path);
        } catch (Throwable $exception) {
            throw MediaUrlResolutionException::forDisk($disk, $path, $exception);
        }
    }
}
