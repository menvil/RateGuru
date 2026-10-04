<?php

namespace App\Services\Media;

use App\Enums\MediaVisibility;
use App\Services\Media\Exceptions\MediaIsNotPublicException;
use App\Services\Media\Exceptions\MediaUrlResolutionException;
use Illuminate\Filesystem\FilesystemManager;
use Throwable;

final class FilesystemMediaUrlResolver implements MediaUrlResolver
{
    public function __construct(
        private readonly FilesystemManager $filesystem,
    ) {}

    public function publicUrl(MediaLocation $location, MediaVisibility $visibility): string
    {
        if ($visibility !== MediaVisibility::Public) {
            throw MediaIsNotPublicException::forLocation($location);
        }

        return $this->resolveUrl($location);
    }

    public function publicUrlOrNull(?MediaLocation $location, ?MediaVisibility $visibility): ?string
    {
        if ($location === null || $visibility !== MediaVisibility::Public) {
            return null;
        }

        return $this->resolveUrl($location);
    }

    private function resolveUrl(MediaLocation $location): string
    {
        try {
            return $this->filesystem->disk($location->disk)->url($location->path);
        } catch (Throwable $exception) {
            throw MediaUrlResolutionException::forDisk($location->disk, $location->path, $exception);
        }
    }
}
