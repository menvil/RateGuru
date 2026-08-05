<?php

namespace App\Services\Media;

use App\Enums\MediaVisibility;
use App\Services\Media\Exceptions\MediaStorageException;
use Illuminate\Filesystem\FilesystemManager;
use Throwable;

final class FilesystemMediaStorage implements MediaStorage
{
    /**
     * Matches media_assets.original_filename's column length, so an unusually
     * long client-supplied filename can't fail the asset insert after the
     * physical upload has already succeeded.
     */
    private const MAX_ORIGINAL_FILENAME_LENGTH = 255;

    public function __construct(
        private readonly FilesystemManager $filesystem,
        private readonly MediaPathGenerator $pathGenerator,
    ) {}

    public function storeNormalized(NormalizedImage $image, MediaStoreRequest $request, ?string $originalFilename): StoredMedia
    {
        $path = $this->pathGenerator->generate($request, $image->extension);

        try {
            $written = $this->filesystem->disk($request->disk)->put(
                $path,
                $image->bytes,
                $request->visibility === MediaVisibility::Public ? 'public' : 'private',
            );
        } catch (Throwable $exception) {
            throw MediaStorageException::couldNotStore($request->disk, $path, $exception);
        }

        if (! $written) {
            throw MediaStorageException::couldNotStore($request->disk, $path);
        }

        return new StoredMedia(
            disk: $request->disk,
            path: $path,
            originalFilename: $originalFilename !== null && mb_strlen($originalFilename) > self::MAX_ORIGINAL_FILENAME_LENGTH
                ? mb_substr($originalFilename, 0, self::MAX_ORIGINAL_FILENAME_LENGTH)
                : $originalFilename,
            mimeType: $image->mimeType,
            extension: $image->extension,
            // Derived from the bytes just written, not trusted from the
            // caller's NormalizedImage::$byteSize, so the persisted metadata
            // can never disagree with what's actually on disk.
            byteSize: strlen($image->bytes),
            width: $image->width,
            height: $image->height,
        );
    }

    public function putContents(MediaLocation $location, string $contents, MediaVisibility $visibility): void
    {
        try {
            $written = $this->filesystem->disk($location->disk)->put(
                $location->path,
                $contents,
                $visibility === MediaVisibility::Public ? 'public' : 'private',
            );
        } catch (Throwable $exception) {
            throw MediaStorageException::couldNotStore($location->disk, $location->path, $exception);
        }

        if (! $written) {
            throw MediaStorageException::couldNotStore($location->disk, $location->path);
        }
    }

    public function exists(MediaLocation $location): bool
    {
        return $this->filesystem->disk($location->disk)->exists($location->path);
    }

    public function size(MediaLocation $location): int
    {
        if (! $this->exists($location)) {
            throw MediaStorageException::notFound($location->disk, $location->path);
        }

        return $this->filesystem->disk($location->disk)->size($location->path);
    }

    public function readStream(MediaLocation $location)
    {
        $stream = $this->filesystem->disk($location->disk)->readStream($location->path);

        if ($stream === null) {
            throw MediaStorageException::notFound($location->disk, $location->path);
        }

        return $stream;
    }

    public function delete(MediaLocation $location): void
    {
        if (! $this->filesystem->disk($location->disk)->delete($location->path)) {
            throw MediaStorageException::couldNotDelete($location->disk, $location->path);
        }
    }
}
