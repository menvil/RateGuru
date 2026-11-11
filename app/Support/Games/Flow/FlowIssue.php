<?php

namespace App\Support\Games\Flow;

/** A problem with a flow: at one step (by its place), or with the flow as a whole (index -1). */
final readonly class FlowIssue
{
    public function __construct(
        public int $index,
        public string $message,
    ) {}
}
