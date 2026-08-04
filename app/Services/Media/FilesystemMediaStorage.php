<?php

namespace App\Services\Media;

use App\Enums\MediaVisibility;
use App\Services\Media\Exceptions\MediaStorageException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
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

    public function store(UploadedFile $file, MediaStoreRequest $request): StoredMedia
    {
        $extension = $file->extension() ?: ($file->getClientOriginalExtension() ?: null);
        $path = $this->pathGenerator->generate($request, $extension);
        // Only a freshly generated path is safe to delete on a later
        // failure — an explicit (deterministic, seeder-reused) path may
        // already have an existing, still-referenced asset sitting at it,
        // and deleting its file out from under it would corrupt that asset.
        $isNewPath = $request->explicitPath === null;
        $dimensions = @getimagesize($file->getRealPath());

        // Read once into memory rather than streaming: byteSize below is
        // strlen() of this exact same string, so the recorded size can
        // never diverge from what's actually written — there's no separate
        // measurement step (of the temp file, or of the disk after the
        // fact) that could disagree with it. Uploads are capped in the
        // low single-digit megabytes (see config/uploads.php), so holding
        // one in memory isn't a concern.
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw MediaStorageException::couldNotReadUploadedFile($file->getClientOriginalName());
        }

        $written = $this->filesystem->disk($request->disk)->put(
            $path,
            $contents,
            $request->visibility === MediaVisibility::Public ? 'public' : 'private',
        );

        if (! $written) {
            throw MediaStorageException::couldNotStore($request->disk, $path);
        }

        // The file now physically exists. Everything below is metadata
        // extraction, not the write itself — a failure here must delete the
        // just-written file rather than leave it orphaned, since no
        // StoredMedia is ever returned for a caller to compensate with —
        // but only when this operation is the one that created it.
        try {
            $originalFilename = $file->getClientOriginalName();

            return new StoredMedia(
                disk: $request->disk,
                path: $path,
                originalFilename: mb_strlen($originalFilename) > self::MAX_ORIGINAL_FILENAME_LENGTH
                    ? mb_substr($originalFilename, 0, self::MAX_ORIGINAL_FILENAME_LENGTH)
                    : $originalFilename,
                mimeType: $file->getMimeType() ?? 'application/octet-stream',
                extension: $extension,
                byteSize: strlen($contents),
                width: $dimensions[0] ?? null,
                height: $dimensions[1] ?? null,
            );
        } catch (Throwable $exception) {
            if ($isNewPath) {
                $this->deleteQuietly(new MediaLocation($request->disk, $path));
            }

            throw $exception;
        }
    }

    /**
     * Best-effort compensation: a cleanup failure is reported but never
     * replaces (or suppresses) the exception that triggered it.
     */
    private function deleteQuietly(MediaLocation $location): void
    {
        try {
            $this->delete($location);
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }

    public function putContents(MediaLocation $location, string $contents, MediaVisibility $visibility): void
    {
        $written = $this->filesystem->disk($location->disk)->put(
            $location->path,
            $contents,
            $visibility === MediaVisibility::Public ? 'public' : 'private',
        );

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
