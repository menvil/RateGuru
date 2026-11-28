<?php

namespace App\Support\Import;

use App\Enums\ImportProvider;

class ImportPreview
{
    public function __construct(
        public readonly ImportProvider $provider,
        public readonly string $sourceUrl,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $imageUrl = null,
        public readonly array $warnings = [],
        public readonly ?string $unsupportedReason = null,
        // A video or animated GIF to import instead of the picture: a direct
        // link to one, or the one a post on X carries.
        public readonly ?string $videoUrl = null,
        // The site refused this server the page — too many requests, or
        // forbidden — rather than the page having nothing to take.
        public readonly bool $refusedByTheSite = false,
    ) {}

    public function hasImage(): bool
    {
        return $this->imageUrl !== null;
    }

    public function isSupported(): bool
    {
        return $this->unsupportedReason === null;
    }
}
