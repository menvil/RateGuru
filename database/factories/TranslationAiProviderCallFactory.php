<?php

namespace Database\Factories;

use App\Models\TranslationAiProviderCall;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Enums\TranslationStatus;
use App\Support\Translations\Telemetry\TranslationAiConsumer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A successful call of ten items, all translated, priced at the configured
 * gpt-6-luna rates.
 *
 * @extends Factory<TranslationAiProviderCall>
 */
class TranslationAiProviderCallFactory extends Factory
{
    protected $model = TranslationAiProviderCall::class;

    public function definition(): array
    {
        return [
            'completed_at' => now('UTC')->subMinutes(5),
            'provider' => 'openai',
            'model' => 'gpt-6-luna-2026-09-01',
            'consumer' => TranslationAiConsumer::GenerateMissing->value,
            'target_locale' => 'de',
            'provider_target_locale' => 'de',
            'status' => TranslationStatus::Success,
            'error_code' => null,
            'http_status' => 200,
            'external_request_id' => 'resp_factory',
            'latency_ms' => 1200,
            'item_count' => 10,
            'succeeded_item_count' => 10,
            'failed_item_count' => 0,
            'item_failures' => null,
            'input_tokens' => 1000,
            'output_tokens' => 200,
            'total_tokens' => 1200,
            'estimated_cost_usd' => '0.000200000000',
            'input_usd_per_million_tokens' => '0.100000',
            'output_usd_per_million_tokens' => '0.500000',
        ];
    }

    /** A call that came to nothing: every item failed with its code, and nothing is known of tokens or cost. */
    public function failedWith(TranslationErrorCode $code, ?int $httpStatus = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TranslationStatus::Failed,
            'error_code' => $code->value,
            'http_status' => $httpStatus,
            'succeeded_item_count' => 0,
            'failed_item_count' => $attributes['item_count'],
            'item_failures' => [$code->value => $attributes['item_count']],
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'estimated_cost_usd' => null,
        ]);
    }
}
