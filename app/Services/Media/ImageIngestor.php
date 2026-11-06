<?php

namespace App\Services\Media;

/**
 * The single boundary every user-controlled image passes through before its
 * bytes ever reach MediaStorage: format detection from actual content, byte/
 * dimension/pixel-count limits, full decode, EXIF re-orientation, re-encode,
 * and metadata stripping. One engine for post images, avatars, and
 * URL-imported images alike — never resizing, cropping, or converting a
 * valid image's format.
 */
interface ImageIngestor
{
    public function ingest(ImageInput $input, ImageIngestPolicy $policy): NormalizedImage;
}
