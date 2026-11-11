<?php

namespace App\Support\Games\Versus;

/**
 * One post of a pair as the crowd comparison sees it: its site votes, and
 * whether it may be shown at all — published, not deleted, its picture there.
 */
final readonly class CrowdSide
{
    public function __construct(
        public ?int $postId,
        public int $upvotes,
        public int $downvotes,
        public bool $eligible = true,
    ) {}

    public function votes(): int
    {
        return $this->upvotes + $this->downvotes;
    }

    /** The upvote share as a percentage, rounded for display only. */
    public function displayShare(): ?float
    {
        $votes = $this->votes();

        return $votes === 0 ? null : round($this->upvotes * 100 / $votes, 1);
    }
}
