<?php

namespace App\Support\Import;

class ImportPreview
{
    public function __construct(
        public readonly string $provider,
        public readonly string $sourceUrl,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $imageUrl = null,
        public readonly array $warnings = [],
        public readonly ?string $unsupportedReason = null,
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
