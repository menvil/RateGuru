<?php

namespace App\Support\Import;

/** What yt-dlp says a page's video is, before anything is downloaded. */
final readonly class YtDlpProbeResult
{
    public function __construct(
        public string $extractor,
        public ?string $title,
        public ?int $durationSeconds,
        public ?int $approximateBytes,
    ) {}
}
