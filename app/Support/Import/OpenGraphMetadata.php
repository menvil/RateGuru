<?php

namespace App\Support\Import;

class OpenGraphMetadata
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $imageUrl = null,
    ) {}
}
