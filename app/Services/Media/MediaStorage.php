<?php

namespace App\Services\Media;

use App\Enums\MediaVisibility;
use App\Services\Media\Exceptions\MediaStorageException;

/**
 * Physical file I/O only — storing, reading, checking, and deleting bytes on
 * a disk. Never resolves a URL; that's MediaUrlResolver's job. Never decodes
 * or validates image content either — that's ImageIngestor's job, upstream
 * of this class. There is no raw-UploadedFile store() here on purpose: every
 * user-controlled image must go through ImageIngestor first, so this
 * interface only accepts an already-normalized image.
 */
interface MediaStorage
{
    public function storeNormalized(NormalizedImage $image, MediaStoreRequest $request, ?string $originalFilename): StoredMedia;

    /**
     * For content generated in-process rather than uploaded (demo seeders'
     * generated placeholder images) — writes at the given, already-decided
     * location instead of generating a new one.
     */
    public function putContents(MediaLocation $location, string $contents, MediaVisibility $visibility): void;

    /**
     * Reads live filesystem state — two calls can legitimately return
     * different results if something else changes the file in between
     * (@phpstan-impure tells PHPStan not to assume a second call must
     * repeat the first's result, which matters for deleteIfExists()'s and
     * lastModified()'s own retry/race-recovery logic).
     *
     * @phpstan-impure
     */
    public function exists(MediaLocation $location): bool;

    public function size(MediaLocation $location): int;

    /** @return resource */
    public function readStream(MediaLocation $location);

    public function delete(MediaLocation $location): void;

    /**
     * Idempotent counterpart to delete(): a missing file is treated as
     * already-deleted success (a no-op), never an exception. A real storage
     * failure (permissions, transport, etc.) still throws. Exists
     * specifically for lifecycle/purge code, which must be safely retryable
     * after a partial failure — delete() itself keeps its stricter contract
     * unchanged for the compensation flows that already depend on it.
     */
    public function deleteIfExists(MediaLocation $location): void;

    /**
     * @return list<string> paths (relative to $disk's root) of every file
     *                      under $directory, recursively
     */
    public function allFiles(string $disk, string $directory): array;

    /** @throws MediaStorageException when missing */
    public function lastModified(MediaLocation $location): int;
}
