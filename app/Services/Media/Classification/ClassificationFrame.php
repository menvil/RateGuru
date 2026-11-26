<?php

namespace App\Services\Media\Classification;

/** One image a classifier looks at: a photo's still, or a video's frame at $atMs. */
final readonly class ClassificationFrame
{
    public function __construct(
        public string $path,
        public ?int $atMs = null,
        // Which variant or frame it is, as recorded with the result.
        public ?string $label = null,
    ) {}
}
