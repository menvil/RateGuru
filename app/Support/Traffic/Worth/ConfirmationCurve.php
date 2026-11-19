<?php

namespace App\Support\Traffic\Worth;

/**
 * How soon accounts are confirmed after the click, read from the accounts
 * of the last working-out: the share confirmed by each age on a grid, and in
 * between on a straight line. A young visit that has no account yet may
 * still bring one; an old one, less so — that is what it is for.
 */
final readonly class ConfirmationCurve
{
    /** @var list<int> ages in seconds: 10 minutes, an hour, 6 hours, 1, 2, 3, 7, 14 and 30 days */
    public const array GRID = [600, 3600, 21600, 86400, 172800, 259200, 604800, 1209600, 2592000];

    public const int TRUSTED_FROM_ACCOUNTS = 30;

    /** @param  list<int>  $counts  accounts confirmed by each age of the grid */
    public function __construct(public array $counts, public int $accounts) {}

    public static function none(): self
    {
        return new self([], 0);
    }

    /** @param  array<string, mixed>|null  $stored */
    public static function fromStored(?array $stored): self
    {
        return $stored === null ? self::none() : new self(array_map('intval', (array) ($stored['counts'] ?? [])), (int) ($stored['accounts'] ?? 0));
    }

    /** @return array{grid: list<int>, counts: list<int>, accounts: int} */
    public function toStored(): array
    {
        return ['grid' => self::GRID, 'counts' => $this->counts, 'accounts' => $this->accounts];
    }

    /** The share of the accounts confirmed by an age, 0 to 1. */
    public function share(int $ageSeconds): float
    {
        if ($this->accounts === 0 || $this->counts === [] || $ageSeconds <= 0) {
            return 0.0;
        }

        $previousAge = 0;
        $previousShare = 0.0;

        foreach (self::GRID as $index => $age) {
            $share = ($this->counts[$index] ?? $this->accounts) / $this->accounts;

            if ($ageSeconds <= $age) {
                return $previousShare + ($share - $previousShare) * ($ageSeconds - $previousAge) / max(1, $age - $previousAge);
            }

            [$previousAge, $previousShare] = [$age, $share];
        }

        return $previousShare;
    }

    /**
     * F: how much of the chance of an account a visit of this age has already
     * used up, against the age it closes at — 0 while too few accounts say.
     */
    public function spent(int $ageSeconds, int $closesAtSeconds): float
    {
        if ($this->accounts < self::TRUSTED_FROM_ACCOUNTS) {
            return 0.0;
        }

        $closing = $this->share($closesAtSeconds);

        return $closing <= 0.0 ? 0.0 : min(1.0, $this->share($ageSeconds) / $closing);
    }
}
