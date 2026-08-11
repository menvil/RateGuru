<?php

namespace App\Services\Media;

use App\Services\Media\Exceptions\MediaVariantGenerationException;

/**
 * Resizes/crops an already-ingested master image into one variant. Never
 * touches storage or the database — pure bytes in, bytes out.
 */
interface ImageVariantProcessor
{
    /**
     * @throws MediaVariantGenerationException
     */
    public function generate(string $masterBytes, string $mimeType, MediaVariantSpecification $specification): GeneratedMediaVariant;
}
