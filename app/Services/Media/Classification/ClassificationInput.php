<?php

namespace App\Services\Media\Classification;

/**
 * What a classifier is shown of one asset: one still for a photo, or a
 * video's frames with their times — each a local image file.
 */
final readonly class ClassificationInput
{
    /**
     * @param  list<ClassificationFrame>  $frames
     */
    public function __construct(
        public int $mediaAssetId,
        public array $frames,
    ) {}
}
