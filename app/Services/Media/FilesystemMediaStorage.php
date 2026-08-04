<?php

namespace App\Services\Media;

use App\Enums\MediaVisibility;
use App\Services\Media\Exceptions\MediaStorageException;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Image\ImageManager;
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
        private readonly ImageManager $imageManager,
    ) {}

    public function store(UploadedFile $file, MediaStoreRequest $request): StoredMedia
    {
        $extension = $file->extension() ?: ($file->getClientOriginalExtension() ?: null);
        $path = $this->pathGenerator->generate($request, $extension);

        // Only a path with nothing sitting at it yet is safe to delete on a
        // later failure. A freshly generated (UUID-based) path is always
        // new, so it's never checked. A demo seeder's deterministic explicit
        // path might be a first-time write (safe to clean up) or a rerun
        // reusing an existing, still-referenced asset (must not be deleted
        // out from under it) — that's the only case worth an existence check
        // before writing.
        $existedBeforeWrite = $request->explicitPath !== null
            && $this->exists(new MediaLocation($request->disk, $path));

        // Read via UploadedFile::getContent() rather than
        // file_get_contents($file->getRealPath()): it throws instead of
        // silently returning false if the temp upload is missing or
        // unreadable, so there's no separate realpath/false check to get
        // wrong. Read once into memory rather than streaming: byteSize below
        // is strlen() of this exact same string, and dimensions are read
        // from the same bytes, so neither can diverge from what's actually
        // written. Uploads are capped in the low single-digit megabytes (see
        // config/uploads.php), so holding one in memory isn't a concern.
        try {
            $contents = $file->getContent();
        } catch (Throwable $exception) {
            throw MediaStorageException::couldNotReadUploadedFile($file->getClientOriginalName(), $exception);
        }

        try {
            $written = $this->filesystem->disk($request->disk)->put(
                $path,
                $contents,
                $request->visibility === MediaVisibility::Public ? 'public' : 'private',
            );
        } catch (Throwable $exception) {
            throw MediaStorageException::couldNotStore($request->disk, $path, $exception);
        }

        if (! $written) {
            throw MediaStorageException::couldNotStore($request->disk, $path);
        }

        // The file now physically exists. Everything below is metadata
        // extraction, not the write itself — a failure here must delete the
        // just-written file rather than leave it orphaned, since no
        // StoredMedia is ever returned for a caller to compensate with —
        // but only when this operation is the one that created it. This
        // includes dimensions: an upload that can't be decoded as an image
        // is a metadata-extraction failure like any other, not something to
        // silently store with a null width/height.
        try {
            [$width, $height] = $this->imageManager->fromBytes($contents)->dimensions();
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
                width: $width,
                height: $height,
            );
        } catch (Throwable $exception) {
            if (! $existedBeforeWrite) {
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
