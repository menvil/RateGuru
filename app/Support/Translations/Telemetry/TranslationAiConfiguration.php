<?php

namespace App\Support\Translations\Telemetry;

/**
 * The machine translation the application is configured for now — read from
 * configuration, never from a provider.
 *
 * $ready says whether a public-content batch, which both consumers send,
 * would be accepted: a provider is chosen and every setting it needs is
 * there. Nothing is sent to find out. $apiKey says whether the provider's
 * entry has an API key — `configured`, `missing`, or `not_required` for an
 * entry that takes none — and never what it is.
 *
 * The rates are those translation.telemetry.pricing gives the configured
 * model, as they would price its next call; null when it has none.
 */
final readonly class TranslationAiConfiguration
{
    public const API_KEY_CONFIGURED = 'configured';

    public const API_KEY_MISSING = 'missing';

    public const API_KEY_NOT_REQUIRED = 'not_required';

    public function __construct(
        public ?string $provider,
        public bool $registered,
        public ?string $model,
        public string $apiKey,
        public bool $ready,
        public ?float $connectTimeoutSeconds,
        public ?float $timeoutSeconds,
        public ?string $inputUsdPerMillionTokens,
        public ?string $outputUsdPerMillionTokens,
    ) {}
}
