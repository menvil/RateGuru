<?php

use App\Models\TranslationAiProviderCall;
use App\Queries\Translations\TranslationAiUsageQuery;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\Translations\Telemetry\TranslationAiConsumer;
use App\Support\Translations\Telemetry\TranslationAiUsageFilter;
use App\Support\Translations\Telemetry\TranslationAiUsageInterval;
use App\Support\Translations\Telemetry\TranslationAiUsagePoint;
use Carbon\CarbonImmutable;

/**
 * Reading Translation AI telemetry back, as the Translation AI page will:
 * figures over a half-open UTC period and its filters, errors by code with
 * provider and item errors apart, a gap-free timeline, and the calls
 * themselves a page at a time.
 */
function translationAiUsage(): TranslationAiUsageQuery
{
    return app(TranslationAiUsageQuery::class);
}

function translationAiPeriod(string $from = '2026-10-01 00:00:00', string $until = '2026-10-02 00:00:00', array $filters = []): TranslationAiUsageFilter
{
    return new TranslationAiUsageFilter(CarbonImmutable::parse($from, 'UTC'), CarbonImmutable::parse($until, 'UTC'), ...$filters);
}

beforeEach(function () {
    $call = fn (string $at, array $attributes = []) => TranslationAiProviderCall::factory()->create(['completed_at' => CarbonImmutable::parse($at, 'UTC'), ...$attributes]);

    // A chunk of Generate missing: ten items, one over its length limit.
    $call('2026-10-01 09:15:00', [
        'latency_ms' => 1000, 'item_count' => 10, 'succeeded_item_count' => 9, 'failed_item_count' => 1,
        'item_failures' => ['constraint_violation' => 1], 'input_tokens' => 1000, 'output_tokens' => 200, 'total_tokens' => 1200,
        'estimated_cost_usd' => '0.000200000000',
    ]);
    // The next chunk timed out.
    TranslationAiProviderCall::factory()->failedWith(TranslationErrorCode::ProviderTimeout)->create([
        'completed_at' => CarbonImmutable::parse('2026-10-01 09:45:00', 'UTC'), 'latency_ms' => 45000,
    ]);
    // Two interactive suggestions into Russian, the second on a model without rates.
    $call('2026-10-01 14:00:00', [
        'consumer' => 'interactive_ai', 'target_locale' => 'ru', 'provider_target_locale' => 'ru', 'latency_ms' => 800,
        'item_count' => 1, 'succeeded_item_count' => 1, 'input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120,
        'estimated_cost_usd' => '0.000020000000',
    ]);
    $call('2026-10-01 14:30:00', [
        'model' => 'gpt-6-luna-mini', 'consumer' => 'interactive_ai', 'target_locale' => 'ru', 'provider_target_locale' => 'ru',
        'latency_ms' => 500, 'item_count' => 1, 'succeeded_item_count' => 1, 'input_tokens' => 50, 'output_tokens' => 10,
        'total_tokens' => 60, 'estimated_cost_usd' => null, 'input_usd_per_million_tokens' => null, 'output_usd_per_million_tokens' => null,
    ]);
    // Another provider, which reported no usage.
    $call('2026-10-01 20:00:00', [
        'provider' => 'local', 'model' => 'qwen', 'latency_ms' => 300, 'item_count' => 5, 'succeeded_item_count' => 5,
        'input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null, 'estimated_cost_usd' => null,
    ]);
    // Just outside the period, on either side.
    $call('2026-09-30 23:59:59');
    $call('2026-10-02 00:00:00');
});

// =============================================================================
// Summary
// =============================================================================

it('sums a period: provider calls and items apart, tokens and the cost that is known', function () {
    $summary = translationAiUsage()->summary(translationAiPeriod());

    expect($summary->calls)->toBe(5)
        ->and($summary->succeededCalls)->toBe(4)
        ->and($summary->failedCalls)->toBe(1)
        ->and($summary->items)->toBe(27)
        ->and($summary->succeededItems)->toBe(16)
        ->and($summary->failedItems)->toBe(11)
        ->and($summary->inputTokens)->toBe(1150)
        ->and($summary->outputTokens)->toBe(230)
        ->and($summary->totalTokens)->toBe(1380)
        ->and($summary->callsWithUnknownUsage)->toBe(2)
        ->and($summary->estimatedCostUsd)->toBe('0.000220000000')
        ->and($summary->callsWithUnknownCost)->toBe(3)
        ->and($summary->averageLatencyMs)->toBe(9520)
        ->and($summary->p95LatencyMs)->toBe(45000)
        ->and($summary->callSuccessRate())->toBe(0.8)
        ->and(round($summary->itemSuccessRate(), 4))->toBe(round(16 / 27, 4));
});

it('narrows a period to a provider, a model, a language or a consumer', function (array $filters, int $calls, string $cost, int $unknownCost) {
    $summary = translationAiUsage()->summary(translationAiPeriod(filters: $filters));

    expect([$summary->calls, $summary->estimatedCostUsd, $summary->callsWithUnknownCost])->toBe([$calls, $cost, $unknownCost]);
})->with([
    'provider' => [['provider' => 'openai'], 4, '0.000220000000', 2],
    'model' => [['model' => 'gpt-6-luna-2026-09-01'], 3, '0.000220000000', 1],
    'language' => [['targetLocale' => 'ru'], 2, '0.000020000000', 1],
    'consumer' => [['consumer' => TranslationAiConsumer::GenerateMissing], 3, '0.000200000000', 2],
    'consumer and model' => [['consumer' => TranslationAiConsumer::InteractiveAi, 'model' => 'gpt-6-luna-mini'], 1, '0.000000000000', 1],
]);

it('counts a period from its start, included, to its end, excluded', function () {
    expect(translationAiUsage()->summary(translationAiPeriod('2026-09-30 23:59:59', '2026-10-01 09:15:00'))->calls)->toBe(1)
        ->and(translationAiUsage()->summary(translationAiPeriod('2026-10-01 20:00:00', '2026-10-02 00:00:01'))->calls)->toBe(2);
});

it('reads its bounds as instants, whatever time zone they are given in', function () {
    $berlin = new TranslationAiUsageFilter(
        CarbonImmutable::parse('2026-10-01 02:00:00', 'Europe/Berlin'),
        CarbonImmutable::parse('2026-10-02 02:00:00', 'Europe/Berlin'),
    );

    expect($berlin->from->toIso8601String())->toBe('2026-10-01T00:00:00+00:00')
        ->and(translationAiUsage()->summary($berlin)->calls)->toBe(5);
});

it('sums tiny costs exactly', function () {
    TranslationAiProviderCall::factory()->count(3)->create([
        'completed_at' => CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC'),
        'estimated_cost_usd' => '0.000000000001',
    ]);

    expect(translationAiUsage()->summary(translationAiPeriod('2026-11-01', '2026-11-02'))->estimatedCostUsd)->toBe('0.000000000003');
});

it('reports an empty period as zeros, with no average or percentile to give', function () {
    $summary = translationAiUsage()->summary(translationAiPeriod('2026-12-01', '2026-12-02'));

    expect([$summary->calls, $summary->items, $summary->inputTokens, $summary->estimatedCostUsd, $summary->averageLatencyMs, $summary->p95LatencyMs, $summary->callSuccessRate()])
        ->toBe([0, 0, 0, '0.000000000000', null, null, null]);
});

it('takes the 95th percentile latency by nearest rank', function () {
    foreach (range(1, 20) as $n) {
        TranslationAiProviderCall::factory()->create(['completed_at' => CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC'), 'latency_ms' => $n * 100]);
    }

    // 20 calls: the 19th fastest is as slow as 95% of them are at most.
    expect(translationAiUsage()->summary(translationAiPeriod('2026-11-01', '2026-11-02'))->p95LatencyMs)->toBe(1900);
});

it('refuses a period that does not end after it starts', function () {
    expect(fn () => translationAiPeriod('2026-10-02', '2026-10-01'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => translationAiPeriod('2026-10-01', '2026-10-01'))->toThrow(InvalidArgumentException::class);
});

// =============================================================================
// Errors
// =============================================================================

it('counts provider errors by call and item errors by item, most frequent first', function () {
    TranslationAiProviderCall::factory()->state(['item_count' => 1])->failedWith(TranslationErrorCode::RateLimited, 429)->count(2)->create([
        'completed_at' => CarbonImmutable::parse('2026-10-01 10:00:00', 'UTC'),
    ]);

    $errors = translationAiUsage()->errors(translationAiPeriod());

    expect($errors->providerErrors)->toBe(['rate_limited' => 2, 'provider_timeout' => 1])
        ->and($errors->itemErrors)->toBe(['provider_timeout' => 10, 'rate_limited' => 2, 'constraint_violation' => 1]);
});

it('reports no errors for a period without any', function () {
    $errors = translationAiUsage()->errors(translationAiPeriod(filters: ['targetLocale' => 'ru']));

    expect($errors->providerErrors)->toBe([])
        ->and($errors->itemErrors)->toBe([]);
});

// =============================================================================
// Timeline
// =============================================================================

it('charts a period hour by hour, with the hours without calls as zeros', function () {
    $points = translationAiUsage()->timeline(translationAiPeriod('2026-10-01 09:00:00', '2026-10-01 15:00:00'), TranslationAiUsageInterval::Hour);

    expect(array_map(fn (TranslationAiUsagePoint $point): string => $point->start->format('H:i'), $points))
        ->toBe(['09:00', '10:00', '11:00', '12:00', '13:00', '14:00'])
        ->and(array_map(fn (TranslationAiUsagePoint $point): int => $point->calls, $points))->toBe([2, 0, 0, 0, 0, 2])
        ->and($points[0])->toEqual(new TranslationAiUsagePoint(
            start: CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'), calls: 2, failedCalls: 1, items: 20, succeededItems: 9,
            failedItems: 11, inputTokens: 1000, outputTokens: 200, totalTokens: 1200, estimatedCostUsd: '0.000200000000', callsWithUnknownCost: 1,
        ))
        ->and($points[1]->estimatedCostUsd)->toBe('0.000000000000')
        ->and([$points[5]->items, $points[5]->inputTokens, $points[5]->estimatedCostUsd, $points[5]->callsWithUnknownCost])->toBe([2, 150, '0.000020000000', 1]);
});

it('charts a period day by day, in UTC', function () {
    $points = translationAiUsage()->timeline(translationAiPeriod('2026-09-30 00:00:00', '2026-10-03 00:00:00'), TranslationAiUsageInterval::Day);

    expect(array_map(fn (TranslationAiUsagePoint $point): string => $point->start->toDateString(), $points))->toBe(['2026-09-30', '2026-10-01', '2026-10-02'])
        ->and(array_map(fn (TranslationAiUsagePoint $point): int => $point->calls, $points))->toBe([1, 5, 1])
        ->and($points[1]->estimatedCostUsd)->toBe('0.000220000000');
});

it('starts a timeline at the hour its period starts in, counting only calls inside the period', function () {
    $points = translationAiUsage()->timeline(translationAiPeriod('2026-10-01 09:30:00', '2026-10-01 10:00:00'), TranslationAiUsageInterval::Hour);

    expect($points)->toHaveCount(1)
        ->and($points[0]->start->format('H:i'))->toBe('09:00')
        ->and($points[0]->calls)->toBe(1)
        ->and($points[0]->failedCalls)->toBe(1);
});

it('refuses a timeline of more points than a chart can use', function () {
    expect(fn () => translationAiUsage()->timeline(translationAiPeriod('2025-01-01', '2026-10-01'), TranslationAiUsageInterval::Hour))
        ->toThrow(InvalidArgumentException::class, 'at most 10000 points');
});

// =============================================================================
// Recent calls
// =============================================================================

it('lists the calls of a period newest first', function () {
    $page = translationAiUsage()->recentCalls(translationAiPeriod(), perPage: 3);

    expect($page->total())->toBe(5)
        ->and($page->getCollection()->map(fn (TranslationAiProviderCall $call): string => $call->completed_at->format('H:i'))->all())
        ->toBe(['20:00', '14:30', '14:00']);
});

it('keeps calls that completed in the same second stable across pages', function () {
    $at = CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC');
    $ids = TranslationAiProviderCall::factory()->count(5)->create(['completed_at' => $at])->pluck('id')->all();
    $period = translationAiPeriod('2026-11-01', '2026-11-02');

    $pages = array_map(
        fn (int $page): array => translationAiUsage()->recentCalls($period, perPage: 2, page: $page)->getCollection()->pluck('id')->all(),
        [1, 2, 3],
    );

    expect($pages)->toBe([array_slice(array_reverse($ids), 0, 2), array_slice(array_reverse($ids), 2, 2), array_slice(array_reverse($ids), 4, 1)])
        ->and(array_merge(...$pages))->toBe(array_reverse($ids));
});

it('holds a page between one and a hundred calls', function () {
    expect(translationAiUsage()->recentCalls(translationAiPeriod(), perPage: 0)->perPage())->toBe(1)
        ->and(translationAiUsage()->recentCalls(translationAiPeriod(), perPage: 1000)->perPage())->toBe(100);
});
