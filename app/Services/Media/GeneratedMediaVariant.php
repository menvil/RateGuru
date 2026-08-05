<?php

namespace App\Services\Media;

/**
 * The fully-encoded, in-memory result of resizing a master image for one
 * MediaVariantSpecification. Not yet written to storage or the database.
 */
final readonly class GeneratedMediaVariant
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $extension,
        public int $byteSize,
        public int $width,
        public int $height,
    ) {}
}
