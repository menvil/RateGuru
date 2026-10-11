<?php

namespace App\Support\Translations\Telemetry;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * What a provider call is estimated to have cost, from the tokens its provider
 * reported and the rates in translation.telemetry.pricing.
 *
 * An estimate, not an invoice: cached input is priced as ordinary input, so it
 * errs high, and the provider's own bill is the figure that counts. It is
 * never a guess either. A model with no rates configured, or a call whose
 * provider did not report both its input and its output tokens — a timeout,
 * say — has an unknown cost: null, never $0, because an unknown cost is not a
 * free call.
 *
 * The arithmetic is exact decimal arithmetic; no float touches a rate or a
 * cost.
 */
final class TranslationAiPricing
{
    /** Decimals of a rate, in USD per million tokens. */
    public const RATE_SCALE = 6;

    /** Decimals of a cost in USD: a single token at a rate of 0.000001 still counts. */
    public const COST_SCALE = 12;

    /** The largest rate and cost the columns hold. */
    private const MAX_RATE = '999999.999999';

    private const MAX_COST = '999999999999.999999999999';

    public function estimate(string $provider, string $model, ?int $inputTokens, ?int $outputTokens): TranslationAiCostEstimate
    {
        $rates = $this->ratesFor($provider, $model);

        if ($rates === null) {
            return new TranslationAiCostEstimate(null, null, null);
        }

        [$input, $output] = $rates;

        if ($inputTokens === null || $outputTokens === null || $inputTokens < 0 || $outputTokens < 0) {
            return new TranslationAiCostEstimate($input, $output, null);
        }

        $cost = BigDecimal::of($inputTokens)->multipliedBy($input)
            ->plus(BigDecimal::of($outputTokens)->multipliedBy($output))
            ->dividedBy(1_000_000, self::COST_SCALE, RoundingMode::HalfUp);

        return new TranslationAiCostEstimate($input, $output, $cost->isGreaterThan(self::MAX_COST) ? null : (string) $cost);
    }

    /**
     * The input and output rates of the model the provider reported: listed
     * exactly, or as the model it is a dated snapshot of. Nothing else
     * matches — gpt-6-luna-mini is not priced as gpt-6-luna.
     *
     * @return array{0: string, 1: string}|null
     */
    private function ratesFor(string $provider, string $model): ?array
    {
        // Read as arrays, never by dot path: a model name may contain a dot.
        $pricing = config('translation.telemetry.pricing');
        $models = is_array($pricing) && is_array($pricing[$provider] ?? null) ? $pricing[$provider] : [];

        $entry = $models[$model] ?? null;

        if ($entry === null && preg_match('/^(.+)-\d{4}-\d{2}-\d{2}$/', $model, $snapshot) === 1) {
            $entry = $models[$snapshot[1]] ?? null;
        }

        if (! is_array($entry)) {
            return null;
        }

        $input = $this->rate($entry['input'] ?? null);
        $output = $this->rate($entry['output'] ?? null);

        return $input !== null && $output !== null ? [$input, $output] : null;
    }

    /** A configured rate at six decimals, or null when it is not one: a malformed rate prices nothing. */
    private function rate(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        try {
            $rate = BigDecimal::of($value)->toScale(self::RATE_SCALE);
        } catch (MathException) {
            return null;
        }

        return $rate->isNegative() || $rate->isGreaterThan(self::MAX_RATE) ? null : (string) $rate;
    }
}
