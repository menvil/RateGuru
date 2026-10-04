<?php

namespace App\Support\Media;

use App\Enums\ImageInputSource;
use App\Services\Media\ImageIngestor;
use App\Services\Media\ImageIngestPolicy;
use App\Services\Media\ImageInput;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaStoreRequest;
use App\Services\Media\StoredMedia;
use Illuminate\Http\UploadedFile;

/**
 * The one call site every user-facing upload flow uses to go from a raw
 * UploadedFile to a stored, normalized image — so post/avatar uploads (and
 * any future entry point) each get a single line instead of re-implementing
 * the ingest-then-store sequence and risking a skipped step.
 */
final class ImageUploadStorer
{
    public function __construct(
        private readonly ImageIngestor $imageIngestor,
        private readonly MediaStorage $mediaStorage,
    ) {}

    public function store(UploadedFile $file, MediaStoreRequest $request, ImageInputSource $source = ImageInputSource::Upload): StoredMedia
    {
        $policy = ImageIngestPolicy::fromConfig();

        $normalized = $this->imageIngestor->ingest(
            ImageInput::fromUploadedFile($file, $policy->maxBytes, $source),
            $policy,
        );

        return $this->mediaStorage->storeNormalized($normalized, $request, $file->getClientOriginalName());
    }
}
