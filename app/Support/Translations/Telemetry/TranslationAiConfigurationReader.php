<?php

namespace App\Support\Translations\Telemetry;

use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationItem;
use App\Support\TranslationEngine\Enums\TranslationDataClassification;
use App\Support\TranslationEngine\Exceptions\TranslationConfigurationException;
use App\Support\Translations\Generation\ProjectTranslationGenerationPlanner;

/**
 * Reads the machine translation the application is configured for now.
 *
 * Whether it is ready is asked the way Generate missing asks before it plans:
 * which provider a batch of public content — what Translation Center sends —
 * would go to now. The engine's router chooses the provider and builds it,
 * which is where a missing setting such as the API key is refused. Building a
 * provider sends nothing; only TranslationService::translate() does, and it is
 * never called here.
 */
final class TranslationAiConfigurationReader
{
    public function __construct(
        private readonly ProjectTranslationGenerationPlanner $planner,
        private readonly TranslationAiPricing $pricing,
    ) {}

    public function read(): TranslationAiConfiguration
    {
        $provider = config('translation.default');
        $provider = is_string($provider) && trim($provider) !== '' ? $provider : null;
        $registry = config('translation.providers');
        $settings = $provider !== null && is_array($registry) && is_array($registry[$provider] ?? null) ? $registry[$provider] : null;
        $model = is_string($settings['model'] ?? null) && trim($settings['model']) !== '' ? $settings['model'] : null;
        $rates = $provider !== null && $model !== null ? $this->pricing->estimate($provider, $model, null, null) : null;

        return new TranslationAiConfiguration(
            provider: $provider,
            registered: $settings !== null,
            model: $model,
            apiKey: match (true) {
                $settings === null || ! array_key_exists('api_key', $settings) => TranslationAiConfiguration::API_KEY_NOT_REQUIRED,
                is_string($settings['api_key']) && trim($settings['api_key']) !== '' => TranslationAiConfiguration::API_KEY_CONFIGURED,
                default => TranslationAiConfiguration::API_KEY_MISSING,
            },
            ready: $this->ready(),
            connectTimeoutSeconds: self::seconds($settings['connect_timeout'] ?? null),
            timeoutSeconds: self::seconds($settings['timeout'] ?? null),
            inputUsdPerMillionTokens: $rates?->inputUsdPerMillionTokens,
            outputUsdPerMillionTokens: $rates?->outputUsdPerMillionTokens,
        );
    }

    private function ready(): bool
    {
        try {
            $this->planner->current(new TranslationBatchRequest(
                targetLocale: 'de',
                dataClassification: TranslationDataClassification::PublicContent,
                items: [new TranslationItem(id: 'configuration-check', sourceLocale: 'en', sourceText: 'Configuration check', contentType: 'configuration.check', multiline: false)],
            ));
        } catch (TranslationConfigurationException) {
            return false;
        }

        return true;
    }

    private static function seconds(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}
