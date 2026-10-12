<?php

namespace App\Support\Translations\Telemetry;

/**
 * Translation AI usage over one filtered period.
 *
 * Provider calls and items are counted apart, because they answer different
 * questions: a call succeeded when the provider answered with something
 * usable; an item succeeded when its translation also passed every check. A
 * successful call can carry failed items, so the two success rates differ;
 * $callsWithFailedItems counts those calls — the provider answered, and some
 * of what it answered did not pass.
 *
 * Token totals add up what providers reported; calls they reported no usage
 * for add nothing and are counted in $callsWithUnknownUsage. The estimated
 * cost adds up the calls whose cost is known, as a decimal string in USD;
 * $callsWithUnknownCost says how many it leaves out — never counted as free.
 */
final readonly class TranslationAiUsageSummary
{
    public function __construct(
        public int $calls,
        public int $succeededCalls,
        public int $failedCalls,
        public int $callsWithFailedItems,
        public int $items,
        public int $succeededItems,
        public int $failedItems,
        public int $inputTokens,
        public int $outputTokens,
        public int $totalTokens,
        public int $callsWithUnknownUsage,
        public string $estimatedCostUsd,
        public int $callsWithUnknownCost,
        public ?int $averageLatencyMs,
        public ?int $p95LatencyMs,
    ) {}

    /** The share of provider calls that succeeded, 0–1, or null with no calls. */
    public function callSuccessRate(): ?float
    {
        return $this->calls > 0 ? $this->succeededCalls / $this->calls : null;
    }

    /** The share of items sent that came back translated, 0–1, or null with no items. */
    public function itemSuccessRate(): ?float
    {
        return $this->items > 0 ? $this->succeededItems / $this->items : null;
    }
}
