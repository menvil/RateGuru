<?php

namespace App\Services\Media;

use App\Enums\MediaVisibility;
use Illuminate\Http\UploadedFile;

/**
 * Physical file I/O only — storing, reading, checking, and deleting bytes on
 * a disk. Never resolves a URL; that's MediaUrlResolver's job.
 */
interface MediaStorage
{
    public function store(UploadedFile $file, MediaStoreRequest $request): StoredMedia;

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
