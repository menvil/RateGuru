<?php

namespace App\Support\Analytics;

use App\Enums\PeriodMetric;

/**
 * How far the visitors one traffic period sent to one version got
 * (GameMetricsQuery::periodFunnel()): each a visitor's assignment, counted
 * once whatever they did on it — started an attempt from it, completed one,
 * saw a result, left an email, reached the flow's goal — and the confirmed
 * accounts credited to those attempts; of the accounts old enough for their
 * activation to be settled (signed up before the activation days were over),
 * those matured and those activated.
 */
final readonly class PeriodVersionFunnel
{
    public function __construct(
        public int $assigned = 0,
        public int $started = 0,
        public int $completed = 0,
        public int $resultViewed = 0,
        public int $emailed = 0,
        public int $goal = 0,
        public int $accounts = 0,
        public int $matured = 0,
        public int $activated = 0,
    ) {}

    /** The count a metric reads. */
    public function count(PeriodMetric $metric): int
    {
        return match ($metric) {
            PeriodMetric::Starts => $this->started,
            PeriodMetric::Completions => $this->completed,
            PeriodMetric::Emails => $this->emailed,
            PeriodMetric::Registrations => $this->accounts,
            PeriodMetric::Activated => $this->activated,
            PeriodMetric::Goal => $this->goal,
        };
    }
}
