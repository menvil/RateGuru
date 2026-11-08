<?php

namespace App\Support\Games\Versus;

use App\Enums\GameCrowdState;

/**
 * Which of two posts the site's crowd prefers: the one with the larger share
 * of upvotes among its votes. This is what each post's own voters said on the
 * site, not how players of the game picked.
 *
 * Shares are compared exactly, by cross-multiplying whole numbers, never by
 * rounded percentages. A pair counts as a crowd pick only when both posts can
 * be shown, each has at least `$minVotes` votes, and the shares differ by at
 * least `$minGap` percentage points — closer than that is too close to call.
 * The gap is a product rule an administrator sets, not a significance test.
 */
final readonly class CrowdComparison
{
    private function __construct(
        public GameCrowdState $state,
        public CrowdSide $a,
        public CrowdSide $b,
        public int $minVotes,
        public int $minGap,
        /** The preferred option's position (0 for A, 1 for B), only for a Majority. */
        public ?int $preferred,
    ) {}

    public static function of(CrowdSide $a, CrowdSide $b, int $minVotes, int $minGap): self
    {
        $state = self::stateOf($a, $b, $minVotes, $minGap);
        $preferred = $state === GameCrowdState::Majority ? (self::crossDifference($a, $b) > 0 ? 0 : 1) : null;

        return new self($state, $a, $b, $minVotes, $minGap, $preferred);
    }

    public function isMajority(): bool
    {
        return $this->state === GameCrowdState::Majority;
    }

    /** The side with too few votes, for a message naming it; null when none is short. */
    public function shortSide(): ?int
    {
        if ($this->a->votes() < $this->minVotes) {
            return 0;
        }

        return $this->b->votes() < $this->minVotes ? 1 : null;
    }

    private static function stateOf(CrowdSide $a, CrowdSide $b, int $minVotes, int $minGap): GameCrowdState
    {
        if ($a->postId === null || $b->postId === null || $a->postId === $b->postId) {
            return GameCrowdState::Missing;
        }

        if (! $a->eligible || ! $b->eligible) {
            return GameCrowdState::Ineligible;
        }

        // At least one vote each, whatever the threshold says: a share of no
        // votes is not a share.
        $required = max(1, $minVotes);

        if ($a->votes() < $required || $b->votes() < $required) {
            return GameCrowdState::Insufficient;
        }

        $difference = self::crossDifference($a, $b);

        if ($difference === 0) {
            return GameCrowdState::Tie;
        }

        // |upA/nA − upB/nB| ≥ gap/100  ⇔  |upA·nB − upB·nA| · 100 ≥ gap · nA · nB
        if (abs($difference) * 100 < $minGap * $a->votes() * $b->votes()) {
            return GameCrowdState::Ambiguous;
        }

        return GameCrowdState::Majority;
    }

    /** upA·nB − upB·nA: positive when A's share is larger, zero when equal. */
    private static function crossDifference(CrowdSide $a, CrowdSide $b): int
    {
        return $a->upvotes * $b->votes() - $b->upvotes * $a->votes();
    }
}
