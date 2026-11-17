<?php

namespace App\Support\Geo\Update;

use Carbon\CarbonImmutable;

/**
 * Whether a run has work to do, worked out from the edition's state alone —
 * no network, no clock of its own. A run is due when it is forced, when the
 * administrator asked for a refresh after the last attempt, when there is no
 * database or no next check yet, or when the next check (or the retry after a
 * failure) has come. A build past the edition's licence age is withdrawn
 * before anything else.
 */
final readonly class GeoUpdatePlan
{
    private function __construct(
        public bool $due,
        public bool $withdraw,
        public bool $dropPrevious,
    ) {}

    /** @param  array<string, mixed>  $state */
    public static function decide(
        array $state,
        GeoEditionSpec $spec,
        CarbonImmutable $now,
        ?CarbonImmutable $refreshRequestedAt,
        bool $force,
        bool $databaseExists,
    ): self {
        $withdraw = false;
        $buildEpoch = $state['installed']['build_epoch'] ?? null;

        if ($databaseExists && $spec->maxBuildAgeDays !== null && is_int($buildEpoch)) {
            $withdraw = $now->getTimestamp() > $buildEpoch + $spec->maxBuildAgeDays * 86400;
        }

        $keptUntil = self::time($state['previous']['kept_until'] ?? null);
        $dropPrevious = $keptUntil !== null && $now->greaterThan($keptUntil);

        $lastAttempt = self::time($state['last_attempt_at'] ?? null);
        $nextCheck = self::time($state['next_check_at'] ?? null);

        $due = $force
            || $withdraw
            || ! $databaseExists
            || $nextCheck === null
            || $now->greaterThanOrEqualTo($nextCheck)
            || ($refreshRequestedAt !== null && ($lastAttempt === null || $refreshRequestedAt->greaterThan($lastAttempt)));

        return new self($due, $withdraw, $dropPrevious);
    }

    /** When to try again after the given number of failures in a row. */
    public static function retryAt(CarbonImmutable $now, int $failures, array $retryAfterMinutes): CarbonImmutable
    {
        $steps = array_values(array_map('intval', $retryAfterMinutes)) ?: [60];
        $minutes = $steps[min(max($failures, 1), count($steps)) - 1];

        return $now->addMinutes($minutes);
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}
