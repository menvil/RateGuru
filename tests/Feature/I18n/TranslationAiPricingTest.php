<?php

use App\Support\Translations\Telemetry\TranslationAiCostEstimate;
use App\Support\Translations\Telemetry\TranslationAiPricing;

/**
 * The estimated cost of a provider call: the reported tokens at the configured
 * rates of the model the provider reported, worked out exactly — and unknown,
 * never $0, whenever either is missing.
 */
function estimateAiCost(string $model, ?int $input, ?int $output, string $provider = 'openai'): TranslationAiCostEstimate
{
    return app(TranslationAiPricing::class)->estimate($provider, $model, $input, $output);
}

it('prices the shipped model at its published rates', function () {
    expect(config('translation.telemetry.pricing.openai'))->toBe(['gpt-6-luna' => ['input' => '0.10', 'output' => '0.50']])
        ->and(estimateAiCost('gpt-6-luna', 1_000_000, 1_000_000))->toEqual(new TranslationAiCostEstimate('0.100000', '0.500000', '0.600000000000'));
});

it('prices a dated snapshot of a listed model as that model, and nothing merely sharing its prefix', function (string $model, ?string $cost, string $provider = 'openai') {
    expect(estimateAiCost($model, 2_000, 400, $provider)->estimatedCostUsd)->toBe($cost);
})->with([
    'the model' => ['gpt-6-luna', '0.000400000000'],
    'a dated snapshot' => ['gpt-6-luna-2026-09-01', '0.000400000000'],
    'another model' => ['gpt-6-luna-mini', null],
    'a snapshot of another model' => ['gpt-6-luna-mini-2026-09-01', null],
    'an undated suffix' => ['gpt-6-luna-preview', null],
    'a different provider\'s model of the same name' => ['gpt-6-luna', null, 'local'],
]);

it('keeps a single token at the smallest rate', function () {
    config(['translation.telemetry.pricing.openai.gpt-6-luna' => ['input' => '0.000001', 'output' => '0']]);

    expect(estimateAiCost('gpt-6-luna', 1, 0)->estimatedCostUsd)->toBe('0.000000000001');
});

it('keeps the rates but no cost when the provider did not report both token counts', function (?int $input, ?int $output) {
    expect(estimateAiCost('gpt-6-luna', $input, $output))->toEqual(new TranslationAiCostEstimate('0.100000', '0.500000', null));
})->with([
    'neither' => [null, null],
    'no output' => [1000, null],
    'no input' => [null, 1000],
]);

it('prices nothing at a rate that is not a decimal of at most six places', function (mixed $rate) {
    config(['translation.telemetry.pricing.openai.gpt-6-luna' => ['input' => $rate, 'output' => '0.50']]);

    expect(estimateAiCost('gpt-6-luna', 1000, 1000))->toEqual(new TranslationAiCostEstimate(null, null, null));
})->with([
    'a float, which could round' => [0.1],
    'seven places' => ['0.0000001'],
    'negative' => ['-0.10'],
    'not a number' => ['ten cents'],
    'missing' => [null],
]);

it('reads a model whose name has a dot in it, which a config path could not', function () {
    config(['translation.telemetry.pricing' => ['openai' => ['gpt-4.1' => ['input' => '2', 'output' => '8']]]]);

    expect(estimateAiCost('gpt-4.1', 1000, 1000)->estimatedCostUsd)->toBe('0.010000000000');
});
