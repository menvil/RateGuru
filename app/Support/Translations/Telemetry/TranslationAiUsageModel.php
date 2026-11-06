<?php

namespace App\Support\Translations\Telemetry;

use Carbon\CarbonImmutable;

/**
 * A model a provider was recorded answering as — its name as the provider
 * reported it, a dated snapshot included — with how many of its calls fall in
 * a period and when it last answered at all.
 */
final readonly class TranslationAiUsageModel
{
    public function __construct(
        public string $provider,
        public string $model,
        public int $calls,
        public CarbonImmutable $lastCallAt,
    ) {}
}
