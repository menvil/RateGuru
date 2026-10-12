<?php

namespace App\Queries\Translations;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Contracts\Persistence\StablePaginationBoundary;
use App\Models\TranslationAiProviderCall;
use App\Support\TranslationEngine\Enums\TranslationStatus;
use App\Support\Translations\Telemetry\TranslationAiErrorBreakdown;
use App\Support\Translations\Telemetry\TranslationAiFailurePattern;
use App\Support\Translations\Telemetry\TranslationAiFailureSeries;
use App\Support\Translations\Telemetry\TranslationAiPricing;
use App\Support\Translations\Telemetry\TranslationAiUsageFilter;
use App\Support\Translations\Telemetry\TranslationAiUsageInterval;
use App\Support\Translations\Telemetry\TranslationAiUsageModel;
use App\Support\Translations\Telemetry\TranslationAiUsagePoint;
use App\Support\Translations\Telemetry\TranslationAiUsageSummary;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use LogicException;

/**
 * Reads Translation AI telemetry back: what the Translation AI page shows,
 * over a period and the filters a TranslationAiUsageFilter names.
 *
 *  - summary()         calls, items, tokens, estimated cost and latency, in figures;
 *  - errors()          provider and item errors by code, kept apart;
 *  - failureSeries()   failed calls by code, and whether they came in a series;
 *  - timeline()        the same figures hour by hour or day by day, for charts;
 *  - recentCalls()     the calls themselves, newest first, a page at a time;
 *  - models()          every model recorded, with its calls in a period;
 *  - lastSucceededAt() when the calls of a filter last succeeded.
 *
 * Aggregate queries over translation_ai_provider_calls, the same on
 * PostgreSQL, MariaDB and SQLite, and everything in UTC. The aggregates are
 * conditional sums and a time bucket Eloquent has no expression for: the raw
 * SQL is this class's own, and every value in it is bound. Read-only.
 */
final class TranslationAiUsageQuery implements RawSqlPersistenceBoundary, StablePaginationBoundary
{
    /** The most points one timeline may have: hourly, a little over a year. */
    public const MAX_TIMELINE_POINTS = 10_000;

    public const MAX_PER_PAGE = 100;

    public function summary(TranslationAiUsageFilter $filter): TranslationAiUsageSummary
    {
        // Raw attributes, read past the model's casts: these are aggregates, not columns.
        $row = $filter->query()
            ->selectRaw(
                'COUNT(*) AS calls,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS succeeded_calls,
                SUM(CASE WHEN status = ? AND failed_item_count > 0 THEN 1 ELSE 0 END) AS calls_with_failed_items,
                SUM(item_count) AS items,
                SUM(succeeded_item_count) AS succeeded_items,
                SUM(failed_item_count) AS failed_items,
                SUM(input_tokens) AS input_tokens,
                SUM(output_tokens) AS output_tokens,
                SUM(total_tokens) AS total_tokens,
                SUM(CASE WHEN input_tokens IS NULL OR output_tokens IS NULL THEN 1 ELSE 0 END) AS calls_with_unknown_usage,
                SUM(estimated_cost_usd) AS estimated_cost_usd,
                SUM(CASE WHEN estimated_cost_usd IS NULL THEN 1 ELSE 0 END) AS calls_with_unknown_cost,
                AVG(latency_ms) AS average_latency_ms',
                [TranslationStatus::Success->value, TranslationStatus::Success->value],
            )
            ->first()
            ?->getAttributes() ?? [];

        $calls = (int) ($row['calls'] ?? 0);
        $succeededCalls = (int) ($row['succeeded_calls'] ?? 0);

        return new TranslationAiUsageSummary(
            calls: $calls,
            succeededCalls: $succeededCalls,
            failedCalls: $calls - $succeededCalls,
            callsWithFailedItems: (int) ($row['calls_with_failed_items'] ?? 0),
            items: (int) ($row['items'] ?? 0),
            succeededItems: (int) ($row['succeeded_items'] ?? 0),
            failedItems: (int) ($row['failed_items'] ?? 0),
            inputTokens: (int) ($row['input_tokens'] ?? 0),
            outputTokens: (int) ($row['output_tokens'] ?? 0),
            totalTokens: (int) ($row['total_tokens'] ?? 0),
            callsWithUnknownUsage: (int) ($row['calls_with_unknown_usage'] ?? 0),
            estimatedCostUsd: $this->usd($row['estimated_cost_usd'] ?? null),
            callsWithUnknownCost: (int) ($row['calls_with_unknown_cost'] ?? 0),
            averageLatencyMs: $calls > 0 ? (int) round((float) $row['average_latency_ms']) : null,
            p95LatencyMs: $this->p95Latency($filter, $calls),
        );
    }

    public function errors(TranslationAiUsageFilter $filter): TranslationAiErrorBreakdown
    {
        $providerErrors = [];

        // Only a failed call has an error code.
        $failedCalls = $filter->query()
            ->whereNotNull('error_code')
            ->selectRaw('error_code, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS calls', [TranslationStatus::Failed->value])
            ->groupBy('error_code')
            ->get();

        foreach ($failedCalls as $call) {
            $row = $call->getAttributes();

            if ((int) $row['calls'] > 0) {
                $providerErrors[(string) $row['error_code']] = (int) $row['calls'];
            }
        }

        // Item failures are a small JSON object per call, summed here rather
        // than with each engine's own JSON functions; only calls with a failed
        // item are read, in id order, a thousand at a time.
        $itemErrors = [];

        $callsWithFailedItems = $filter->query()
            ->where('failed_item_count', '>', 0)
            ->select(['id', 'item_failures'])
            ->lazyById(1000);

        foreach ($callsWithFailedItems as $call) {
            foreach ($call->item_failures ?? [] as $code => $count) {
                $itemErrors[$code] = ($itemErrors[$code] ?? 0) + $count;
            }
        }

        return new TranslationAiErrorBreakdown($this->mostFrequentFirst($providerErrors), $this->mostFrequentFirst($itemErrors));
    }

    /**
     * One point per hour or day from the one the period starts in to the one
     * it ends in, each counting only calls inside the period; a point without
     * calls is all zeros.
     *
     * @return list<TranslationAiUsagePoint>
     *
     * @throws InvalidArgumentException when the period has more than MAX_TIMELINE_POINTS points
     */
    public function timeline(TranslationAiUsageFilter $filter, TranslationAiUsageInterval $interval): array
    {
        $starts = [];

        for ($start = $interval->start($filter->from); $start->lessThan($filter->until); $start = $interval->next($start)) {
            if (count($starts) === self::MAX_TIMELINE_POINTS) {
                throw new InvalidArgumentException('A Translation AI usage timeline has at most '.self::MAX_TIMELINE_POINTS.' points; choose a shorter period or a wider interval.');
            }

            $starts[] = $start;
        }

        $bucket = $this->bucketExpression($interval);

        $rows = $filter->query()
            ->selectRaw(
                "{$bucket} AS bucket,
                COUNT(*) AS calls,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS failed_calls,
                SUM(item_count) AS items,
                SUM(succeeded_item_count) AS succeeded_items,
                SUM(failed_item_count) AS failed_items,
                SUM(input_tokens) AS input_tokens,
                SUM(output_tokens) AS output_tokens,
                SUM(total_tokens) AS total_tokens,
                SUM(estimated_cost_usd) AS estimated_cost_usd,
                SUM(CASE WHEN estimated_cost_usd IS NULL THEN 1 ELSE 0 END) AS calls_with_unknown_cost",
                [TranslationStatus::Failed->value],
            )
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        return array_map(function ($start) use ($rows, $interval): TranslationAiUsagePoint {
            $row = $rows->get($start->format($interval->keyFormat()))?->getAttributes() ?? [];

            return new TranslationAiUsagePoint(
                start: $start,
                calls: (int) ($row['calls'] ?? 0),
                failedCalls: (int) ($row['failed_calls'] ?? 0),
                items: (int) ($row['items'] ?? 0),
                succeededItems: (int) ($row['succeeded_items'] ?? 0),
                failedItems: (int) ($row['failed_items'] ?? 0),
                inputTokens: (int) ($row['input_tokens'] ?? 0),
                outputTokens: (int) ($row['output_tokens'] ?? 0),
                totalTokens: (int) ($row['total_tokens'] ?? 0),
                estimatedCostUsd: $this->usd($row['estimated_cost_usd'] ?? null),
                callsWithUnknownCost: (int) ($row['calls_with_unknown_cost'] ?? 0),
            );
        }, $starts);
    }

    /**
     * The calls themselves, newest first — or, $withIssues, only those with
     * something to look at: a failed call, or a successful one with failed
     * items. One page is read, never the period.
     *
     * @return LengthAwarePaginator<int, TranslationAiProviderCall>
     */
    public function recentCalls(TranslationAiUsageFilter $filter, int $perPage = 25, int $page = 1, bool $withIssues = false): LengthAwarePaginator
    {
        return $filter->query()
            ->when($withIssues, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('status', TranslationStatus::Failed->value)
                ->orWhere('failed_item_count', '>', 0)))
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->paginate(min(max(1, $perPage), self::MAX_PER_PAGE), ['*'], 'page', max(1, $page));
    }

    /**
     * The failed provider calls of a period by error code, most frequent
     * first, each with its pattern: Isolated, a Burst — five or more within a
     * sliding ten minutes — or Ongoing, a series that reaches into the fifteen
     * minutes before $now.
     *
     * Read from the failed calls' codes and times only, in order, one row at a
     * time, so a long outage is still read in constant memory.
     *
     * @return list<TranslationAiFailureSeries>
     */
    public function failureSeries(TranslationAiUsageFilter $filter, ?DateTimeInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now())->utc();
        $failures = $filter->query()
            ->where('status', TranslationStatus::Failed->value)
            ->whereNotNull('error_code')
            ->select(['id', 'error_code', 'completed_at'])
            ->orderBy('error_code')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->cursor();

        /** @var array<string, array{calls: int, first: int, last: int, window: list<int>, series: ?array{start: int, end: int, calls: int, through: int}}> $codes */
        $codes = [];

        foreach ($failures as $failure) {
            $code = (string) $failure->error_code;
            $at = $failure->completed_at->getTimestamp();
            $state = $codes[$code] ?? ['calls' => 0, 'first' => $at, 'last' => $at, 'window' => [], 'series' => null];

            $state['calls']++;
            $state['last'] = $at;
            // The times of this failure and the four before it: a series when they fall within the window.
            $state['window'] = array_slice([...$state['window'], $at], -TranslationAiFailurePattern::SERIES_CALLS);

            if (count($state['window']) === TranslationAiFailurePattern::SERIES_CALLS
                && $at - $state['window'][0] <= TranslationAiFailurePattern::SERIES_WINDOW_SECONDS) {
                $series = $state['series'];
                $index = $state['calls'];

                // A window overlapping the series before it extends that series; one that does not starts a new one.
                $state['series'] = $series !== null && $series['through'] >= $index - TranslationAiFailurePattern::SERIES_CALLS + 1
                    ? ['start' => $series['start'], 'end' => $at, 'calls' => $series['calls'] + $index - $series['through'], 'through' => $index]
                    : ['start' => $state['window'][0], 'end' => $at, 'calls' => TranslationAiFailurePattern::SERIES_CALLS, 'through' => $index];
            }

            $codes[$code] = $state;
        }

        $at = fn (int $timestamp): CarbonImmutable => CarbonImmutable::createFromTimestamp($timestamp, 'UTC');
        $ongoingSince = $now->getTimestamp() - TranslationAiFailurePattern::ONGOING_SECONDS;
        $result = [];

        foreach ($codes as $code => $state) {
            $series = $state['series'];

            $result[] = new TranslationAiFailureSeries(
                errorCode: $code,
                calls: $state['calls'],
                firstFailedAt: $at($state['first']),
                lastFailedAt: $at($state['last']),
                pattern: match (true) {
                    $series === null => TranslationAiFailurePattern::Isolated,
                    $series['end'] >= $ongoingSince => TranslationAiFailurePattern::Ongoing,
                    default => TranslationAiFailurePattern::Burst,
                },
                seriesStartedAt: $series !== null ? $at($series['start']) : null,
                seriesEndedAt: $series !== null ? $at($series['end']) : null,
                seriesCalls: $series['calls'] ?? 0,
                callsSinceSeries: $series !== null ? $state['calls'] - $series['through'] : 0,
            );
        }

        usort($result, fn (TranslationAiFailureSeries $a, TranslationAiFailureSeries $b): int => [$b->calls, $a->errorCode] <=> [$a->calls, $b->errorCode]);

        return $result;
    }

    /**
     * Every model a provider was recorded answering as — kept for the
     * retention period — with how many of its calls completed in [$from,
     * $until) and when it last answered at all, most recently used first.
     * The list a model filter offers: only models that have history.
     *
     * @return list<TranslationAiUsageModel>
     */
    public function models(DateTimeInterface $from, DateTimeInterface $until): array
    {
        $period = new TranslationAiUsageFilter($from, $until);
        $models = [];

        $rows = TranslationAiProviderCall::query()
            ->selectRaw(
                'provider, model, SUM(CASE WHEN completed_at >= ? AND completed_at < ? THEN 1 ELSE 0 END) AS calls, MAX(completed_at) AS last_call_at',
                [$period->from, $period->until],
            )
            ->groupBy('provider', 'model')
            ->get();

        foreach ($rows as $row) {
            $attributes = $row->getAttributes();

            $models[] = new TranslationAiUsageModel(
                provider: (string) $attributes['provider'],
                model: (string) $attributes['model'],
                calls: (int) $attributes['calls'],
                lastCallAt: CarbonImmutable::parse((string) $attributes['last_call_at'], 'UTC'),
            );
        }

        usort($models, fn (TranslationAiUsageModel $a, TranslationAiUsageModel $b): int => [$b->lastCallAt->getTimestamp(), $a->provider, $a->model] <=> [$a->lastCallAt->getTimestamp(), $b->provider, $b->model]);

        return $models;
    }

    /** When a call of $filter last succeeded, or null when none did. */
    public function lastSucceededAt(TranslationAiUsageFilter $filter): ?CarbonImmutable
    {
        $at = $filter->query()->where('status', TranslationStatus::Success->value)->max('completed_at');

        return is_string($at) || $at instanceof DateTimeInterface ? CarbonImmutable::parse($at, 'UTC') : null;
    }

    /**
     * The 95th percentile by nearest rank: the latency at least 95% of the
     * calls were as fast as. One indexed-order read; no engine-specific
     * percentile function.
     */
    private function p95Latency(TranslationAiUsageFilter $filter, int $calls): ?int
    {
        if ($calls === 0) {
            return null;
        }

        $latency = $filter->query()
            ->orderBy('latency_ms')
            ->offset((int) ceil(0.95 * $calls) - 1)
            ->limit(1)
            ->value('latency_ms');

        return $latency === null ? null : (int) $latency;
    }

    /** The point a call falls in, as the database writes it — what keyFormat() writes in PHP. */
    private function bucketExpression(TranslationAiUsageInterval $interval): string
    {
        $driver = (new TranslationAiProviderCall)->getConnection()->getDriverName();
        $hour = $interval === TranslationAiUsageInterval::Hour;

        return match ($driver) {
            'pgsql' => $hour ? "to_char(completed_at, 'YYYY-MM-DD HH24')" : "to_char(completed_at, 'YYYY-MM-DD')",
            'mysql', 'mariadb' => $hour ? "DATE_FORMAT(completed_at, '%Y-%m-%d %H')" : "DATE_FORMAT(completed_at, '%Y-%m-%d')",
            'sqlite' => $hour ? "strftime('%Y-%m-%d %H', completed_at)" : "strftime('%Y-%m-%d', completed_at)",
            default => throw new LogicException("Translation AI usage cannot group by time on the {$driver} driver."),
        };
    }

    /** A sum of costs as a decimal string in USD; SQLite hands it back as a float. */
    private function usd(mixed $sum): string
    {
        $value = match (true) {
            is_float($sum) => sprintf('%.'.TranslationAiPricing::COST_SCALE.'F', $sum),
            is_int($sum), is_string($sum) => (string) $sum,
            default => '0',
        };

        return (string) BigDecimal::of($value)->toScale(TranslationAiPricing::COST_SCALE, RoundingMode::HalfUp);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function mostFrequentFirst(array $counts): array
    {
        uksort($counts, static fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return $counts;
    }
}
