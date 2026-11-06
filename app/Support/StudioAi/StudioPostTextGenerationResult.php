<?php

namespace App\Support\StudioAi;

/** A batch of post texts written, and the call that wrote it. */
final readonly class StudioPostTextGenerationResult
{
    public function __construct(
        public StudioPostTextSuggestions $suggestions,
        public StudioPostTextGenerationCall $call,
    ) {}
}
