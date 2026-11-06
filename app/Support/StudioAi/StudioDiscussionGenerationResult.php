<?php

namespace App\Support\StudioAi;

/**
 * What the model answered — the one structured object of a completed
 * response, decoded, and nothing else checked yet — and the call that
 * answered it. Whether it is a usable proposal is StudioDiscussionProposal's
 * to say, on the plan as it is when it is kept.
 */
final readonly class StudioDiscussionGenerationResult
{
    /** @param  array<mixed>  $answer */
    public function __construct(
        public array $answer,
        public StudioDiscussionGenerationCall $call,
    ) {}
}
