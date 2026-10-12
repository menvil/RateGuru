<?php

namespace App\Support\Translations\Telemetry;

/**
 * The estimated cost of one provider call, with the rates it was worked out
 * from, as decimal strings. The rates are null when the model has none
 * configured; the cost is null whenever it cannot be known.
 */
final readonly class TranslationAiCostEstimate
{
    public function __construct(
        public ?string $inputUsdPerMillionTokens,
        public ?string $outputUsdPerMillionTokens,
        public ?string $estimatedCostUsd,
    ) {}
}
