<?php

namespace App\Support\Import;

class OpenGraphMetadata
{
    /**
     * @param  list<string>  $imageCandidates  up to the first ten images the page names (OpenGraphParser::MOST_IMAGE_CANDIDATES), in order of preference; `imageUrl` is the first
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $imageUrl = null,
        public readonly array $imageCandidates = [],
    ) {}
}
