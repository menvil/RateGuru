<?php

namespace App\Services\Media;

use App\Enums\MediaVisibility;

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

    public function exists(MediaLocation $location): bool;

    public function size(MediaLocation $location): int;

    /** @return resource */
    public function readStream(MediaLocation $location);

    public function delete(MediaLocation $location): void;
}
