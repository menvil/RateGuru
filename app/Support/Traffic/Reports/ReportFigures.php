<?php

namespace App\Support\Traffic\Reports;

use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Money\TrafficMoney;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The figures of one row of a traffic report — visits, how far they got,
 * the accounts they brought, what they cost and what they are worth, and of
 * that worth what the accounts brought — read the same whatever the engine
 * returned, added up, and turned into the rates the screens show. New
 * visitors are the visits less the known ones: an account can only come from
 * them. Then how many carried each sign of not being a person (BotSignals),
 * and — where Sites & zones adds them (withAddresses()) — how many came from
 * an address the same place sent another visit from.
 */
final readonly class ReportFigures
{
    public function __construct(
        public int $visits = 0,
        public int $opened = 0,
        public int $started = 0,
        public int $completed = 0,
        public int $goal = 0,
        public int $accounts = 0,
        public int $posted = 0,
        public int $returned = 0,
        public int $knownOpened = 0,
        public string $spend = '0',
        public string $spendEstimated = '0',
        public string $knownSpend = '0',
        public string $valueReal = '0',
        public string $valueExpected = '0',
        public string $valueAccounts = '0',
        public int $flagged = 0,
        public int $crawlers = 0,
        public int $prefetches = 0,
        public int $headless = 0,
        public int $noBrowserHeaders = 0,
        public int $bursts = 0,
        public int $addressed = 0,
        public int $repeatAddresses = 0,
    ) {}

    /** @param  array<string, mixed>|object  $row */
    public static function fromRow(array|object $row): self
    {
        $row = (array) $row;

        return new self(
            (int) ($row['visits'] ?? 0),
            (int) ($row['opened'] ?? 0),
            (int) ($row['started'] ?? 0),
            (int) ($row['completed'] ?? 0),
            (int) ($row['goal'] ?? 0),
            (int) ($row['accounts'] ?? 0),
            (int) ($row['posted'] ?? 0),
            (int) ($row['returned'] ?? 0),
            (int) ($row['known_opened'] ?? 0),
            TrafficMoney::stored($row['spend'] ?? 0),
            TrafficMoney::stored($row['spend_estimated'] ?? 0),
            TrafficMoney::stored($row['known_spend'] ?? 0),
            TrafficMoney::stored($row['value_real'] ?? 0),
            TrafficMoney::stored($row['value_expected'] ?? 0),
            TrafficMoney::stored($row['value_accounts'] ?? 0),
            (int) ($row['flagged'] ?? 0),
            (int) ($row['crawlers'] ?? 0),
            (int) ($row['prefetches'] ?? 0),
            (int) ($row['headless'] ?? 0),
            (int) ($row['no_browser_headers'] ?? 0),
            (int) ($row['bursts'] ?? 0),
        );
    }

    public function plus(self $other): self
    {
        $sum = fn (string $a, string $b): string => TrafficMoney::stored(TrafficMoney::of($a)->plus(TrafficMoney::of($b)));

        return new self(
            visits: $this->visits + $other->visits,
            opened: $this->opened + $other->opened,
            started: $this->started + $other->started,
            completed: $this->completed + $other->completed,
            goal: $this->goal + $other->goal,
            accounts: $this->accounts + $other->accounts,
            posted: $this->posted + $other->posted,
            returned: $this->returned + $other->returned,
            knownOpened: $this->knownOpened + $other->knownOpened,
            spend: $sum($this->spend, $other->spend),
            spendEstimated: $sum($this->spendEstimated, $other->spendEstimated),
            knownSpend: $sum($this->knownSpend, $other->knownSpend),
            valueReal: $sum($this->valueReal, $other->valueReal),
            valueExpected: $sum($this->valueExpected, $other->valueExpected),
            valueAccounts: $sum($this->valueAccounts, $other->valueAccounts),
            flagged: $this->flagged + $other->flagged,
            crawlers: $this->crawlers + $other->crawlers,
            prefetches: $this->prefetches + $other->prefetches,
            headless: $this->headless + $other->headless,
            noBrowserHeaders: $this->noBrowserHeaders + $other->noBrowserHeaders,
            bursts: $this->bursts + $other->bursts,
            addressed: $this->addressed + $other->addressed,
            repeatAddresses: $this->repeatAddresses + $other->repeatAddresses,
        );
    }

    /** Spend with no visit to share it over, added to the row it belongs to. */
    public function withSpend(string $extra): self
    {
        return $this->with(['spend' => TrafficMoney::stored(TrafficMoney::of($this->spend)->plus(TrafficMoney::of($extra)))]);
    }

    /**
     * Of its visits with an address on record — kept as long as a visit's
     * details are — how many there were, and how many came from an address
     * the same place sent another visit from.
     */
    public function withAddresses(int $addressed, int $repeatAddresses): self
    {
        return $this->with(['addressed' => $addressed, 'repeatAddresses' => $repeatAddresses]);
    }

    /** Visits with any sign of not being a person, of all, 0–1; null without visits. */
    public function flaggedRate(): ?float
    {
        return $this->visits === 0 ? null : $this->flagged / $this->visits;
    }

    /** Visits from an address the same place sent another from, of those with an address on record, 0–1. */
    public function repeatAddressRate(): ?float
    {
        return $this->addressed === 0 ? null : $this->repeatAddresses / $this->addressed;
    }

    /**
     * Each sign of not being a person the visits carried, with how many: the
     * words BotSignals names them by, most common first, none left out.
     *
     * @return array<string, int>
     */
    public function flags(): array
    {
        $counts = array_filter([
            BotSignals::WORDS[BotSignals::CRAWLER] => $this->crawlers,
            BotSignals::WORDS[BotSignals::PREFETCH] => $this->prefetches,
            BotSignals::WORDS[BotSignals::HEADLESS] => $this->headless,
            BotSignals::WORDS[BotSignals::NO_BROWSER_HEADERS] => $this->noBrowserHeaders,
            BotSignals::WORDS[BotSignals::BURST] => $this->bursts,
        ]);
        arsort($counts);

        return $counts;
    }

    public function newVisitors(): int
    {
        return max(0, $this->opened - $this->knownOpened);
    }

    public function value(): BigDecimal
    {
        return TrafficMoney::of($this->valueReal)->plus(TrafficMoney::of($this->valueExpected));
    }

    /** Value less spend, over spend, in percent; null without spend. */
    public function roi(): ?float
    {
        $spend = TrafficMoney::of($this->spend);

        return $spend->isZero() ? null : (float) (string) $this->value()->minus($spend)->multipliedBy(100)->dividedBy($spend, 4, RoundingMode::HalfUp);
    }

    /** What an account cost; null without accounts. */
    public function costPerAccount(): ?string
    {
        return $this->accounts === 0 ? null : (string) TrafficMoney::of($this->spend)->dividedBy($this->accounts, 4, RoundingMode::HalfUp);
    }

    public function openRate(): ?float
    {
        return $this->visits === 0 ? null : $this->opened / $this->visits;
    }

    public function startRate(): ?float
    {
        return $this->opened === 0 ? null : $this->started / $this->opened;
    }

    public function accountRate(): ?float
    {
        return $this->newVisitors() === 0 ? null : $this->accounts / $this->newVisitors();
    }

    /** @param  array<string, int|string>  $changes */
    private function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
