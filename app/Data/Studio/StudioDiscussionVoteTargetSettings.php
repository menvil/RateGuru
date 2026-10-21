<?php

namespace App\Data\Studio;

/**
 * How many vote one prepared comment or reply up and down, how much of an
 * automatic share it gets (`priority`: low, normal or high — a setting of the
 * share only, never a part of a vote), and when its votes come: the first
 * `firstSeconds` after the comment itself, the rest spread over
 * `spreadSeconds`.
 */
final readonly class StudioDiscussionVoteTargetSettings
{
    /** The weight each priority gives a comment's share of the votes distributed automatically. */
    public const WEIGHTS = ['low' => 1, 'normal' => 2, 'high' => 3];

    public function __construct(
        public int $nodeId,
        public int $up = 0,
        public int $down = 0,
        public string $priority = 'normal',
        public int $firstSeconds = 300,
        public int $spreadSeconds = 21_600,
    ) {}

    public function total(): int
    {
        return $this->up + $this->down;
    }
}
