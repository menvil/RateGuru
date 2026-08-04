<?php

namespace App\Services\Media;

final readonly class ImageIngestPolicy
{
    public function __construct(
        public int $maxBytes,
        public int $maxWidth,
        public int $maxHeight,
        public int $maxPixels,
        public int $jpegQuality,
        public int $pngCompression,
        public int $webpQuality,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxBytes: (int) config('uploads.images.max_kilobytes') * 1024,
            maxWidth: (int) config('uploads.images.max_width'),
            maxHeight: (int) config('uploads.images.max_height'),
            maxPixels: (int) config('uploads.images.max_pixels'),
            jpegQuality: (int) config('uploads.images.jpeg_quality'),
            pngCompression: (int) config('uploads.images.png_compression'),
            webpQuality: (int) config('uploads.images.webp_quality'),
        );
    }
}
