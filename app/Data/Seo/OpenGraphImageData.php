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

    /**
     * A URL scheme is case-insensitive (RFC 3986 §3.1) and parse_url does not
     * normalise it, so `HTTPS://host/image.jpg` is a perfectly valid secure URL
     * that a case-sensitive comparison would drop the og:image:secure_url tag
     * for.
     */
    public function secureUrl(): ?string
    {
        return strtolower((string) parse_url($this->url, PHP_URL_SCHEME)) === 'https'
            ? $this->url
            : null;
    }
}
