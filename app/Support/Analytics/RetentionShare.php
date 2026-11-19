<?php

namespace App\Support\Analytics;

/**
 * How many of some new accounts came back: the accounts, those of them that
 * returned, and the fewest accounts a rate is reported for — below it the
 * share is too small to say anything, and it reads "n < 30" rather than a
 * rate. Pure.
 */
final readonly class RetentionShare
{
    public function __construct(
        public int $returned,
        public int $users,
        public int $minimum,
    ) {}

    /** Whether there are too few accounts to report a rate. */
    public function isSmall(): bool
    {
        return $this->users < max(1, $this->minimum);
    }

    /** The share that came back, from 0 to 1; none below the fewest accounts a rate is reported for. */
    public function rate(): ?float
    {
        return $this->isSmall() ? null : $this->returned / $this->users;
    }

    /** The rate as the tab writes it: a whole percent; none below the fewest accounts. */
    public function percent(): ?int
    {
        $rate = $this->rate();

        return $rate === null ? null : (int) round($rate * 100);
    }
}
