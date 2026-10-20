<?php

namespace App\Support\StudioAi;

/** A photo described, and the call that described it. */
final readonly class StudioImageAnalysisResult
{
    public function __construct(
        public StudioImageDescription $description,
        public StudioImageAnalysisCall $call,
    ) {}
}
