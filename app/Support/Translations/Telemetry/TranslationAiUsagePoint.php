<?php

namespace App\Support\Translations\Telemetry;

use Carbon\CarbonImmutable;

/**
 * One hour or one day of a Translation AI usage timeline, starting at $start
 * (UTC). A period with no calls is a point of zeros, so a chart has no gaps.
 */
final readonly class TranslationAiUsagePoint
{
    public function __construct(
        public CarbonImmutable $start,
        public int $calls,
        public int $failedCalls,
        public int $items,
        public int $succeededItems,
        public int $failedItems,
        public int $inputTokens,
        public int $outputTokens,
        public int $totalTokens,
        public string $estimatedCostUsd,
        public int $callsWithUnknownCost,
    ) {}
}
