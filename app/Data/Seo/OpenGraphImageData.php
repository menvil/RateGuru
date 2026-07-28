<?php

namespace App\Data\Seo;

final readonly class OpenGraphImageData
{
    public function __construct(
        public string $url,
        public ?string $mimeType,
        public ?int $width,
        public ?int $height,
        public string $alt,
    ) {}

    public function secureUrl(): ?string
    {
        return parse_url($this->url, PHP_URL_SCHEME) === 'https'
            ? $this->url
            : null;
    }
}
