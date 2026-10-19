<?php

namespace App\Support\StudioAi;

/**
 * A stored photo, read for one analysis request: its bytes and what they
 * are. Held in memory for the one request only — never stored, queued,
 * cached or logged — and never shown by a dump or a trace.
 */
final readonly class StudioAnalysisImage
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public ?int $width,
        public ?int $height,
    ) {}

    /** The bytes as the data URL a request carries them in. */
    public function dataUrl(): string
    {
        return 'data:'.$this->mimeType.';base64,'.base64_encode($this->bytes);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'mimeType' => $this->mimeType,
            'byteSize' => strlen($this->bytes),
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
