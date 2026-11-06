<?php

namespace App\Support\Translations\Telemetry;

use Carbon\CarbonImmutable;

/**
 * The failed provider calls of one error code over a period: how many, when,
 * and whether they came in a series (TranslationAiFailurePattern).
 *
 * The latest series, when there is one, is told by when it started and ended
 * and how many failed calls it took; $callsSinceSeries counts the failures of
 * the same code after it, so "a burst yesterday, none since" can be said.
 */
final readonly class TranslationAiFailureSeries
{
    public function __construct(
        public string $errorCode,
        public int $calls,
        public CarbonImmutable $firstFailedAt,
        public CarbonImmutable $lastFailedAt,
        public TranslationAiFailurePattern $pattern,
        public ?CarbonImmutable $seriesStartedAt = null,
        public ?CarbonImmutable $seriesEndedAt = null,
        public int $seriesCalls = 0,
        public int $callsSinceSeries = 0,
    ) {}
}
