<?php

namespace App\Support\Analytics;

use App\Support\Traffic\Money\TrafficMoney;

/**
 * The people of an Analytics figure and what they did, read the same
 * whatever the engine returned. People are browsers: each count is of
 * different browsers — a visitor on two days, or with two visits, once —
 * except the votes, comments and posts, which are counted as made, and the
 * spend. New visitors are those not known as they came: only they can become
 * a new account, so the account and activation rates are shares of them;
 * the start rate is a share of every visitor, completion of those who
 * started. Matured accounts are those whose days to activate are over.
 * Returning visitors are counted on their own (AnalyticsVisitsQuery::returning()).
 *
 * A rate is none with nothing to divide by, and none below the denominator a
 * table row needs before its rate is shown rather than "—", when one is given.
 */
final readonly class VisitorFigures
{
    public function __construct(
        public int $visitors = 0,
        public int $newVisitors = 0,
        public int $starters = 0,
        public int $completers = 0,
        public int $goal = 0,
        public int $signupForm = 0,
        public int $signups = 0,
        public int $accounts = 0,
        public int $activated = 0,
        public int $maturedAccounts = 0,
        public int $votes = 0,
        public int $voters = 0,
        public int $comments = 0,
        public int $commenters = 0,
        public int $posts = 0,
        public int $returning = 0,
        public string $spend = '0',
        public string $spendEstimated = '0',
    ) {}

    /** @param  array<string, mixed>|object  $row */
    public static function fromRow(array|object $row): self
    {
        $row = (array) $row;
        $count = fn (string $key): int => (int) ($row[$key] ?? 0);

        return new self(
            $count('visitors'),
            $count('new_visitors'),
            $count('starters'),
            $count('completers'),
            $count('goal'),
            $count('signup_form'),
            $count('signups'),
            $count('accounts'),
            $count('activated'),
            $count('matured_accounts'),
            $count('votes'),
            $count('voters'),
            $count('comments'),
            $count('commenters'),
            $count('posts'),
            $count('returning'),
            TrafficMoney::stored($row['spend'] ?? 0),
            TrafficMoney::stored($row['spend_estimated'] ?? 0),
        );
    }

    /** The same figures with the returning visitors counted. */
    public function withReturning(int $returning): self
    {
        return new self($this->visitors, $this->newVisitors, $this->starters, $this->completers, $this->goal, $this->signupForm, $this->signups,
            $this->accounts, $this->activated, $this->maturedAccounts, $this->votes, $this->voters, $this->comments, $this->commenters, $this->posts,
            $returning, $this->spend, $this->spendEstimated);
    }

    /** The same figures with the accounts whose days to activate are over counted. */
    public function withMaturedAccounts(int $matured): self
    {
        return new self($this->visitors, $this->newVisitors, $this->starters, $this->completers, $this->goal, $this->signupForm, $this->signups,
            $this->accounts, $this->activated, $matured, $this->votes, $this->voters, $this->comments, $this->commenters, $this->posts,
            $this->returning, $this->spend, $this->spendEstimated);
    }

    /** Whether nobody is counted: what each tab shows its empty state for. */
    public function isEmpty(): bool
    {
        return $this->visitors === 0;
    }

    /** Whether any of the spend is an estimate rather than what the network charged. */
    public function isEstimated(): bool
    {
        return ! TrafficMoney::of($this->spendEstimated)->isZero();
    }

    /** Starters over visitors. */
    public function startRate(int $minimum = 1): ?float
    {
        return self::rate($this->starters, $this->visitors, $minimum);
    }

    /** Completers over starters. */
    public function completion(int $minimum = 1): ?float
    {
        return self::rate($this->completers, $this->starters, $minimum);
    }

    /** Accounts over new visitors. */
    public function accountRate(int $minimum = 1): ?float
    {
        return self::rate($this->accounts, $this->newVisitors, $minimum);
    }

    /** Activated accounts over new visitors. */
    public function activationRate(int $minimum = 1): ?float
    {
        return self::rate($this->activated, $this->newVisitors, $minimum);
    }

    /**
     * A count over what it is a share of; none when that is below the
     * minimum — at least one, so never a division by nothing.
     */
    public static function rate(int $count, int $of, int $minimum = 1): ?float
    {
        return $of < max(1, $minimum) ? null : $count / $of;
    }
}
