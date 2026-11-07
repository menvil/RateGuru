<?php

namespace App\Support\Import;

class OpenGraphMetadata
{
    /**
     * @param  list<string>  $imageCandidates  every image the page names, in order of preference; `imageUrl` is the first
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $imageUrl = null,
        public readonly array $imageCandidates = [],
    ) {}
}
