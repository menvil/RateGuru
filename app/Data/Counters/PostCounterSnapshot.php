<?php

namespace App\Data\Counters;

final readonly class PostCounterSnapshot
{
    public function __construct(
        public int $upvotes,
        public int $downvotes,
    ) {}
}
