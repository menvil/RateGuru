<?php

namespace App\Support\Media;

/**
 * Everything a Blade template needs to play one post's video or animation
 * in a context: the file to play there, its picture size, the poster shown
 * until it plays, and whether it has sound worth a sound button.
 */
final readonly class PostVideo
{
    public function __construct(
        public string $src,
        public string $mimeType,
        public ?string $poster,
        public ?int $width,
        public ?int $height,
        public bool $hasAudio,
        public ?int $durationMs,
    ) {}
}
