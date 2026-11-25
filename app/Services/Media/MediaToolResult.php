<?php

namespace App\Services\Media;

final readonly class MediaToolResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
