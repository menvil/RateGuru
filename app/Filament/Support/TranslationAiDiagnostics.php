<?php

namespace App\Filament\Support;

use App\Jobs\Translations\GenerateProjectTranslationChunkJob;
use App\Models\TranslationAiProviderCall;
use App\Queries\Translations\TranslationAiUsageQuery;
use App\Support\Locale\LocaleManager;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Enums\TranslationStatus;
use App\Support\Translations\Telemetry\TranslationAiConfiguration;
use App\Support\Translations\Telemetry\TranslationAiConfigurationReader;
use App\Support\Translations\Telemetry\TranslationAiConsumer;
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
use Illuminate\Support\Str;
use ReflectionProperty;

/**
 * What the Translation AI page shows, worked out from what is recorded and
 * configured — the page draws it and decides nothing.
 *
 * Two subjects, kept apart on purpose:
 *
 *  - Provider health is the configuration translations run on now: the
 *    configured provider and model (with its dated snapshots, as providers
 *    report them), whatever model the page is filtered to. A filter on an old
 *    model never changes it.
 *  - Usage, failures and the recent calls are the history the filters choose:
 *    a period, and optionally one model as the provider reported it.
 *
 * Every figure comes from TranslationAiUsageQuery over translation_ai_provider_calls
 * — aggregates, and one page of calls — and the configuration from
 * TranslationAiConfigurationReader. Nothing is sent to a provider, and
 * nothing recorded but metadata is ever read: no text, prompt or credential
 * exists here to show. Times are UTC, as the application's are.
 */
final class TranslationAiDiagnostics
{
    /** The periods the page offers, by the value its URL carries. */
    public const PERIODS = ['24h' => '24 h', '7d' => '7 days', '30d' => '30 days'];

    public const DEFAULT_PERIOD = '7d';

    /** All calls, or only those with something to look at. */
    public const CALL_FILTERS = ['all', 'issues'];

    public const CALLS_PER_PAGE = 25;

    /** How recent a successful call must be for the configuration to count as healthy. */
    public const HEALTHY_WITHIN_HOURS = 24;

    /** The failures every page lists, happened or not; any other code is listed once it happens. */
    public const LISTED_ERRORS = [
        TranslationErrorCode::ConstraintViolation,
        TranslationErrorCode::ProviderTimeout,
        TranslationErrorCode::InvalidProviderResponse,
        TranslationErrorCode::RateLimited,
        TranslationErrorCode::AuthenticationFailed,
        TranslationErrorCode::ProviderUnavailable,
    ];

    public function __construct(
        private readonly TranslationAiUsageQuery $usage,
        private readonly TranslationAiConfigurationReader $configuration,
        private readonly LocaleManager $locales,
    ) {}

    /** A period the page offers, or the default for anything else. */
    public static function period(mixed $period): string
    {
        return is_string($period) && array_key_exists($period, self::PERIODS) ? $period : self::DEFAULT_PERIOD;
    }

    /** A call filter the page offers, or All for anything else. */
    public static function callFilter(mixed $filter): string
    {
        return in_array($filter, self::CALL_FILTERS, true) ? $filter : 'all';
    }

    /** A page number, or the first page for anything that is not one. */
    public static function page(mixed $page): int
    {
        $page = filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $page === false ? 1 : $page;
    }

    /**
     * Everything the page draws for one view of it.
     *
     * @return array<string, mixed>
     */
    public function build(string $period, mixed $model, string $calls, int $page, CarbonImmutable $now): array
    {
        $now = $now->utc();
        [$from, $until, $interval] = self::bounds($period, $now);
        $configuration = $this->configuration->read();
        $models = $this->usage->models($from, $until);
        $options = $this->modelOptions($models, $configuration);
        $model = is_string($model) && array_key_exists($model, $options) && $model !== '' ? $model : null;
        $filter = new TranslationAiUsageFilter($from, $until, model: $model);
        $summary = $this->usage->summary($filter);
        $label = 'last '.self::periodWords($period);

        return [
            'updatedAt' => $now->format('H:i').' UTC',
            'selectedPeriod' => $period,
            'periodLabel' => Str::ucfirst($label),
            'selectedModel' => $model ?? '',
            'modelOptions' => array_values($options),
            'health' => $this->health($configuration, $models, $from, $until, $label, $now),
            'usage' => $this->usageBlock($summary, $filter, $interval, $model, $label),
            'failures' => $this->failures($summary, $filter, $model, $label, $now),
            'recent' => $this->callsBlock($summary, $filter, $calls, $page, $model, $label, $configuration, $now),
            'configuration' => $this->configurationRows($configuration),
        ];
    }

    /**
     * A period by whole hours or days, ending with the current one, so its
     * chart has exactly 24, 7 or 30 points and covers the very calls its
     * figures count.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: TranslationAiUsageInterval}
     */
    private static function bounds(string $period, CarbonImmutable $now): array
    {
        return match ($period) {
            '24h' => [$now->startOfHour()->subHours(23), $now->startOfHour()->addHour(), TranslationAiUsageInterval::Hour],
            '30d' => [$now->startOfDay()->subDays(29), $now->startOfDay()->addDay(), TranslationAiUsageInterval::Day],
            default => [$now->startOfDay()->subDays(6), $now->startOfDay()->addDay(), TranslationAiUsageInterval::Day],
        };
    }

    private static function periodWords(string $period): string
    {
        return match ($period) {
            '24h' => '24 hours',
            '30d' => '30 days',
            default => '7 days',
        };
    }

    // The model filter -----------------------------------------------------------------------

    /**
     * All models, then the configured model as providers reported it, then
     * every other model recorded, most recently used first. The configured
     * model is offered even before it has a call, so the filter can show it
     * has none. One option per model name, whichever providers reported it.
     *
     * @param  list<TranslationAiUsageModel>  $models
     * @return array<string, array<string, mixed>>
     */
    private function modelOptions(array $models, TranslationAiConfiguration $configuration): array
    {
        $byName = [];

        foreach ($models as $recorded) {
            $byName[$recorded->model] ??= ['providers' => [], 'calls' => 0, 'last' => $recorded->lastCallAt];
            $byName[$recorded->model]['providers'][$recorded->provider] = true;
            $byName[$recorded->model]['calls'] += $recorded->calls;
        }

        $current = fn (string $name): bool => $configuration->model !== null && TranslationAiPricing::isModelOrSnapshotOf($name, $configuration->model);

        if ($configuration->model !== null && array_filter(array_keys($byName), $current) === []) {
            $byName = [$configuration->model => ['providers' => [(string) $configuration->provider => true], 'calls' => 0, 'last' => null], ...$byName];
        }

        uksort($byName, fn (string $a, string $b): int => [$current($b) ? 1 : 0] <=> [$current($a) ? 1 : 0]);

        $total = array_sum(array_column($byName, 'calls'));
        $options = ['' => [
            'value' => '',
            'label' => 'All models',
            'icon' => 'boxes',
            'meta' => number_format(count($byName)).' '.Str::plural('model', count($byName)).' with recorded calls or configured',
            'trailing' => self::calls($total),
            'badge' => ['label' => number_format(count($byName)).' '.Str::plural('model', count($byName)), 'tone' => 'outline', 'dot' => false],
            'search' => 'all models',
        ]];

        foreach ($byName as $name => $model) {
            $isCurrent = $current($name);
            $providers = implode(', ', array_keys($model['providers']));

            $options[$name] = [
                'value' => $name,
                'label' => $name,
                'icon' => 'cpu',
                'mono' => true,
                'meta' => $providers.' · '.match (true) {
                    $isCurrent && $model['last'] === null => 'Configured now · no calls recorded',
                    $isCurrent => 'Configured now',
                    default => 'Last call '.$model['last']->format('j M Y'),
                },
                'trailing' => self::calls($model['calls']),
                'badge' => $isCurrent ? ['label' => 'Current', 'tone' => 'success', 'dot' => true] : ['label' => 'Not current', 'tone' => 'neutral', 'dot' => false],
                'search' => "{$name} {$providers}",
            ];
        }

        return $options;
    }

    // Provider health ------------------------------------------------------------------------

    /**
     * The configured provider and model, from configuration and the calls
     * made on them — never from the model filter.
     *
     * Not configured: the router would refuse a batch, before sending it.
     * Degraded: the provider rejected the API key and no call has succeeded
     * since — once is enough, since a rejected key fails every call after it;
     * or one kind of provider failure is ongoing — five or more failed calls
     * within ten minutes, reaching into the last fifteen. Healthy: a call
     * succeeded within the last day, and neither is the case. Configured:
     * ready, but no recent success to say it works.
     *
     * @param  list<TranslationAiUsageModel>  $models
     * @return array<string, mixed>
     */
    private function health(TranslationAiConfiguration $configuration, array $models, CarbonImmutable $from, CarbonImmutable $until, string $label, CarbonImmutable $now): array
    {
        $family = $configuration->model === null ? [] : array_values(array_unique([
            $configuration->model,
            ...array_map(
                fn (TranslationAiUsageModel $model): string => $model->model,
                array_filter($models, fn (TranslationAiUsageModel $model): bool => $model->provider === $configuration->provider && TranslationAiPricing::isModelOrSnapshotOf($model->model, (string) $configuration->model)),
            ),
        ]));
        $current = fn (CarbonImmutable $from, CarbonImmutable $until): TranslationAiUsageFilter => new TranslationAiUsageFilter($from, $until, provider: $configuration->provider ?? '', models: $family);

        $summary = $this->usage->summary($current($from, $until));
        $lastSuccess = $this->usage->lastSucceededAt($current($now->subYears(10), $until));
        $recentWindow = TranslationAiFailurePattern::SERIES_WINDOW_SECONDS + TranslationAiFailurePattern::ONGOING_SECONDS;
        $ongoing = array_values(array_filter(
            $this->usage->failureSeries($current($now->subSeconds($recentWindow), $until), $now),
            fn (TranslationAiFailureSeries $series): bool => $series->pattern === TranslationAiFailurePattern::Ongoing,
        ));
        // The last call that tested the key: a success, or a rejection of it. Timeouts and the like say nothing about it.
        $keyTest = $this->usage->latestSuccessOrFailure($current($now->subYears(10), $until), TranslationErrorCode::AuthenticationFailed);
        $keyRejectedAt = $keyTest !== null && $keyTest->status === TranslationStatus::Failed ? CarbonImmutable::instance($keyTest->completed_at)->utc() : null;
        $healthy = $lastSuccess !== null && $lastSuccess->greaterThanOrEqualTo($now->subHours(self::HEALTHY_WITHIN_HOURS));

        [$status, $reason] = match (true) {
            ! $configuration->ready => [
                ['label' => 'Not configured', 'tone' => 'danger', 'dot' => false],
                match (true) {
                    $configuration->provider === null => 'No translation provider is configured. Translations are refused before anything is sent.',
                    ! $configuration->registered => 'The configured provider is not registered. Translations are refused before anything is sent.',
                    $configuration->apiKey === TranslationAiConfiguration::API_KEY_MISSING => 'The API key is missing. Translations are refused before anything is sent.',
                    default => 'A provider setting is missing or invalid. Translations are refused before anything is sent.',
                },
            ],
            $keyRejectedAt !== null => [
                ['label' => 'Degraded', 'tone' => 'warning', 'dot' => false],
                'The provider rejected the API key '.self::moment($keyRejectedAt, $now).' UTC ('.TranslationErrorCode::AuthenticationFailed->value.'), and no call has succeeded since.',
            ],
            $ongoing !== [] => [
                ['label' => 'Degraded', 'tone' => 'warning', 'dot' => false],
                $ongoing[0]->errorCode.' is failing repeatedly: '.number_format($ongoing[0]->seriesCalls).' failed calls since '.$ongoing[0]->seriesStartedAt?->format('H:i').' UTC.',
            ],
            $healthy => [
                ['label' => 'Healthy', 'tone' => 'success', 'dot' => true],
                'A call succeeded '.self::ago($lastSuccess, $now).', the API key was not rejected since, and no failure is ongoing.',
            ],
            default => [
                ['label' => 'Configured', 'tone' => 'neutral', 'dot' => false],
                'Ready to translate, but no call succeeded in the last '.self::HEALTHY_WITHIN_HOURS.' hours to confirm it works.',
            ],
        };

        $hasCalls = $summary->calls > 0;

        return [
            'status' => $status,
            'reason' => $reason,
            'subtitle' => 'Current configuration · '.$label,
            'rows' => [
                ['icon' => 'server', 'label' => 'Provider', 'value' => $configuration->provider ?? 'None', 'mono' => $configuration->provider !== null, 'muted' => $configuration->provider === null],
                ['icon' => 'cpu', 'label' => 'Model', 'value' => $configuration->model ?? 'None', 'mono' => $configuration->model !== null, 'muted' => $configuration->model === null],
                ['icon' => 'key-round', 'label' => 'API key', 'badge' => match ($configuration->apiKey) {
                    TranslationAiConfiguration::API_KEY_CONFIGURED => ['label' => 'Configured', 'tone' => 'success', 'dot' => true],
                    TranslationAiConfiguration::API_KEY_MISSING => ['label' => 'Not configured', 'tone' => 'danger', 'dot' => false],
                    default => ['label' => 'Not required', 'tone' => 'neutral', 'dot' => false],
                }],
                ['icon' => 'circle-check', 'label' => 'Last success', 'value' => $lastSuccess !== null ? Str::ucfirst(self::moment($lastSuccess, $now)).' UTC · '.self::ago($lastSuccess, $now) : 'Never', 'muted' => $lastSuccess === null],
                ['icon' => 'timer', 'label' => 'Average response', 'value' => $hasCalls ? self::seconds((int) $summary->averageLatencyMs) : 'No calls', 'muted' => ! $hasCalls],
                ['icon' => 'gauge', 'label' => 'p95 latency', 'value' => $hasCalls ? self::seconds((int) $summary->p95LatencyMs) : 'No calls', 'muted' => ! $hasCalls],
                ['icon' => 'percent', 'label' => 'Success rate', 'value' => $hasCalls ? self::percentage($summary->succeededCalls, $summary->calls).' · '.number_format($summary->succeededCalls).' of '.self::calls($summary->calls) : 'No calls', 'muted' => ! $hasCalls],
                ['icon' => 'rotate-ccw', 'label' => 'Retry policy', 'value' => 'None'],
                ['icon' => 'git-branch', 'label' => 'Fallback provider', 'value' => 'None'],
            ],
        ];
    }

    // Usage and cost --------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function usageBlock(TranslationAiUsageSummary $summary, TranslationAiUsageFilter $filter, TranslationAiUsageInterval $interval, ?string $model, string $label): array
    {
        $has = $summary->calls > 0;
        $unknownCost = $summary->callsWithUnknownCost;

        return [
            'subtitle' => Str::ucfirst($label).($model !== null ? " · {$model}" : '').' · cost estimated from configured list prices',
            'has' => $has,
            'empty' => 'No provider calls'.($model !== null ? " for {$model}" : '')." in the {$label}.",
            'figures' => [
                [
                    'label' => 'Provider calls',
                    'value' => number_format($summary->calls),
                    'note' => $has ? number_format($summary->failedCalls).' failed' : null,
                    'due' => false,
                ],
                [
                    'label' => 'Translated items',
                    'value' => number_format($summary->succeededItems),
                    'note' => $has ? 'of '.number_format($summary->items).' sent' : null,
                    'due' => false,
                ],
                [
                    'label' => 'Tokens in · out',
                    'value' => self::compact($summary->inputTokens).' · '.self::compact($summary->outputTokens),
                    'note' => $summary->callsWithUnknownUsage > 0 ? self::calls($summary->callsWithUnknownUsage).' without reported usage' : null,
                    'due' => false,
                ],
                [
                    'label' => 'Estimated cost',
                    'value' => $has && $unknownCost === $summary->calls ? 'Unknown' : self::money($summary->estimatedCostUsd),
                    'note' => $unknownCost > 0 ? self::calls($unknownCost).' with unknown cost' : null,
                    'due' => $unknownCost > 0,
                ],
            ],
            'chart' => $has ? $this->chart($this->usage->timeline($filter, $interval), $interval) : null,
        ];
    }

    /**
     * One bar per hour or day: succeeded calls under failed ones, both scaled
     * to the busiest point.
     *
     * @param  list<TranslationAiUsagePoint>  $points
     * @return array<string, mixed>
     */
    private function chart(array $points, TranslationAiUsageInterval $interval): array
    {
        $hourly = $interval === TranslationAiUsageInterval::Hour;
        $max = max(1, ...array_map(fn (TranslationAiUsagePoint $point): int => $point->calls, $points));
        $last = count($points) - 1;
        $ticks = $hourly ? [0, 6, 12, 18, $last] : ($last < 7 ? range(0, $last) : [0, 7, 14, 21, $last]);
        $bars = [];

        foreach ($points as $index => $point) {
            $name = $hourly ? $point->start->format('j M H:00') : $point->start->format('j M');
            $succeeded = $point->calls - $point->failedCalls;

            $bars[] = [
                'tick' => in_array($index, $ticks, true) ? ($hourly ? $point->start->format('H:00') : $point->start->format('j M')) : null,
                'succeeded' => round($succeeded / $max * 100, 2),
                'failed' => round($point->failedCalls / $max * 100, 2),
                'hasSucceeded' => $succeeded > 0,
                'hasFailed' => $point->failedCalls > 0,
                'title' => $name.' · '.self::calls($point->calls).($point->failedCalls > 0 ? ' · '.number_format($point->failedCalls).' failed' : ''),
            ];
        }

        return [
            'title' => $hourly ? 'Calls per hour' : 'Calls per day',
            'dense' => count($points) > 14,
            'max' => self::calls($max),
            'bars' => $bars,
        ];
    }

    // Failures and issues ------------------------------------------------------------------------

    /**
     * Provider failures by call and item failures by item, side by side and
     * never added up: one timeout of a ten-item request is one failed call and
     * ten failed items. Only provider failures have a pattern; an item the
     * provider answered for and a check refused says nothing about the
     * provider being down.
     *
     * @return array<string, mixed>
     */
    private function failures(TranslationAiUsageSummary $summary, TranslationAiUsageFilter $filter, ?string $model, string $label, CarbonImmutable $now): array
    {
        $series = [];

        foreach ($this->usage->failureSeries($filter, $now) as $entry) {
            $series[$entry->errorCode] = $entry;
        }

        $itemErrors = $this->usage->errors($filter)->itemErrors;
        $codes = array_values(array_unique([
            ...array_keys($series),
            ...array_keys($itemErrors),
            ...array_map(fn (TranslationErrorCode $code): string => $code->value, self::LISTED_ERRORS),
        ]));
        $failedItems = array_sum($itemErrors);
        $ongoing = array_values(array_filter($series, fn (TranslationAiFailureSeries $entry): bool => $entry->pattern === TranslationAiFailurePattern::Ongoing));
        $bursts = array_values(array_filter($series, fn (TranslationAiFailureSeries $entry): bool => $entry->pattern === TranslationAiFailurePattern::Burst));
        // The latest burst is the one that ended last, whichever code had the most failures.
        usort($bursts, fn (TranslationAiFailureSeries $a, TranslationAiFailureSeries $b): int => ($b->seriesEndedAt?->getTimestamp() ?? 0) <=> ($a->seriesEndedAt?->getTimestamp() ?? 0));
        $failedCalls = array_sum(array_map(fn (TranslationAiFailureSeries $entry): int => $entry->calls, $series));

        $rows = array_map(function (string $code) use ($series, $itemErrors, $now): array {
            $entry = $series[$code] ?? null;
            $items = $itemErrors[$code] ?? 0;

            return [
                'code' => $code,
                'description' => self::describe($code),
                'happened' => $entry !== null || $items > 0,
                'calls' => $entry !== null ? self::count($entry->calls, 'failed call') : null,
                'items' => $items > 0 ? self::count($items, 'failed item') : null,
                'last' => $entry !== null ? 'Last failed call '.self::moment($entry->lastFailedAt, $now) : null,
                'pattern' => $entry === null ? null : [
                    'label' => $entry->pattern->label(),
                    'tone' => match ($entry->pattern) {
                        TranslationAiFailurePattern::Ongoing => 'danger',
                        TranslationAiFailurePattern::Burst => 'warning',
                        TranslationAiFailurePattern::Isolated => 'neutral',
                    },
                    'hint' => match ($entry->pattern) {
                        TranslationAiFailurePattern::Ongoing => 'A series of failures is still going on',
                        TranslationAiFailurePattern::Burst => 'Five or more failed calls within ten minutes',
                        TranslationAiFailurePattern::Isolated => 'Single failures with no series',
                    },
                ],
                // Items only: the provider answered, and a check refused what it sent back.
                'itemsOnly' => $entry === null && $items > 0,
            ];
        }, $codes);

        usort($rows, fn (array $a, array $b): int => [$b['happened'], (int) ($series[$b['code']]->calls ?? 0), $itemErrors[$b['code']] ?? 0] <=> [$a['happened'], (int) ($series[$a['code']]->calls ?? 0), $itemErrors[$a['code']] ?? 0]);

        $notice = match (true) {
            $summary->calls === 0 => ['tone' => 'info', 'text' => 'No provider calls'.($model !== null ? " for {$model}" : '')." in the {$label}."],
            $ongoing !== [] => ['tone' => 'danger', 'text' => 'Systemic issue: '.$ongoing[0]->errorCode.' since '.$ongoing[0]->seriesStartedAt?->format('H:i').' UTC and still failing — '.self::count($ongoing[0]->seriesCalls, 'failed call').' in this series.'],
            $bursts !== [] => ['tone' => 'success', 'text' => 'No systemic issue now. The latest burst: '.self::count($bursts[0]->seriesCalls, $bursts[0]->errorCode.' failure').' in '.self::minutes($bursts[0]).', '.self::moment($bursts[0]->seriesStartedAt ?? $now, $now).' UTC; '.($bursts[0]->callsSinceSeries > 0 ? number_format($bursts[0]->callsSinceSeries).' since.' : 'none since.')],
            $failedCalls > 0 => ['tone' => 'success', 'text' => 'No systemic issue. '.self::count($failedCalls, 'isolated failed call').($failedItems > 0 ? '; '.self::count($failedItems, 'failed item').' in all.' : '.')],
            $failedItems > 0 => ['tone' => 'success', 'text' => 'No provider failures. '.self::count($failedItems, 'item').' did not pass the checks of the translations the provider returned.'],
            default => ['tone' => 'success', 'text' => 'No issues in this period.'],
        };

        return [
            'subtitle' => Str::ucfirst($label).($model !== null ? " · {$model}" : '').' · by error type',
            'badge' => match (true) {
                $summary->calls === 0 => null,
                $ongoing !== [] => ['label' => 'Systemic issue', 'tone' => 'danger'],
                default => ['label' => 'No systemic issue', 'tone' => 'success'],
            },
            'notice' => $notice,
            'rows' => $rows,
        ];
    }

    private static function describe(string $code): string
    {
        return match (TranslationErrorCode::tryFrom($code)) {
            TranslationErrorCode::ConstraintViolation => 'A returned translation broke its item’s rules: blank, too long, a line break or a lost placeholder',
            TranslationErrorCode::ProviderTimeout => 'No response within the provider timeout',
            TranslationErrorCode::InvalidProviderResponse => 'The response was not the structured output the request required',
            TranslationErrorCode::RateLimited => 'The provider asked to slow down (HTTP 429)',
            TranslationErrorCode::AuthenticationFailed => 'The provider rejected the API key (HTTP 401 or 403)',
            TranslationErrorCode::ProviderUnavailable => 'The provider could not be reached or failed on its side',
            TranslationErrorCode::ProviderRejectedRequest => 'The provider rejected the request itself, such as an unknown model',
            TranslationErrorCode::ProviderRefused => 'The model declined to translate the content',
            TranslationErrorCode::RequestTooLarge => 'The provider refused a request as too large (HTTP 413)',
            TranslationErrorCode::NotConfigured => 'Machine translation was not configured',
            TranslationErrorCode::ClassificationNotAllowed => 'The provider may not receive this kind of content',
            null => 'Not a known error code',
        };
    }

    private static function minutes(TranslationAiFailureSeries $series): string
    {
        $seconds = ($series->seriesEndedAt?->getTimestamp() ?? 0) - ($series->seriesStartedAt?->getTimestamp() ?? 0);

        return self::count(max(1, (int) ceil($seconds / 60)), 'min', 'min');
    }

    // Recent provider calls --------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function callsBlock(TranslationAiUsageSummary $summary, TranslationAiUsageFilter $filter, string $which, int $page, ?string $model, string $label, TranslationAiConfiguration $configuration, CarbonImmutable $now): array
    {
        $withIssues = $which === 'issues';
        $calls = $this->usage->recentCalls($filter, self::CALLS_PER_PAGE, $page, $withIssues);

        // A page past the end — an old link, or calls deleted since — shows the last one instead.
        if ($calls->isEmpty() && $page > 1 && $calls->total() > 0) {
            $calls = $this->usage->recentCalls($filter, self::CALLS_PER_PAGE, $calls->lastPage(), $withIssues);
        }

        $issues = $summary->failedCalls + $summary->callsWithFailedItems;
        $for = $model !== null ? " for {$model}" : '';

        return [
            'filter' => $which,
            'filters' => ['all' => 'All · '.number_format($summary->calls), 'issues' => 'With issues · '.number_format($issues)],
            'rows' => array_map(fn (TranslationAiProviderCall $call): array => $this->callRow($call, $configuration->timeoutSeconds, $now), $calls->items()),
            'paginator' => $calls,
            'noun' => $withIssues ? 'calls with issues' : 'calls',
            'empty' => match (true) {
                $calls->total() > 0 => null,
                $withIssues && $summary->calls > 0 => ['title' => "No calls with issues in the {$label}", 'body' => "Every call{$for} succeeded with every item translated.", 'tone' => 'success'],
                default => ['title' => "No provider calls{$for} in the {$label}", 'body' => 'Calls appear here once AI translate, Suggest alternative, Regenerate or Generate missing reach a provider.', 'tone' => 'neutral'],
            },
        ];
    }

    /**
     * One recorded call, as the table shows it — its metadata and nothing
     * else; a call records no text to show.
     *
     * @return array<string, mixed>
     */
    private function callRow(TranslationAiProviderCall $call, ?float $timeout, CarbonImmutable $now): array
    {
        $completed = CarbonImmutable::instance($call->completed_at)->utc();
        $interactive = $call->consumer === TranslationAiConsumer::InteractiveAi->value;
        $failed = $call->status === TranslationStatus::Failed;
        $failures = is_array($call->item_failures) ? $call->item_failures : [];

        return [
            'time' => $completed->format('H:i:s'),
            'date' => self::day($completed, $now),
            'usage' => $interactive ? 'Interactive AI' : (TranslationAiConsumer::tryFrom($call->consumer) === TranslationAiConsumer::GenerateMissing ? 'Generate missing' : Str::headline($call->consumer)),
            'usageIcon' => $interactive ? 'sparkles' : 'layers',
            'usageNote' => $interactive ? 'One field' : 'Background',
            'provider' => $call->provider,
            'model' => $call->model,
            'flag' => $this->locales->isSupported($call->target_locale) ? $this->locales->flag($call->target_locale) : '',
            'language' => $this->locales->isSupported($call->target_locale) ? $this->locales->label($call->target_locale) : $call->target_locale,
            'locale' => $call->target_locale,
            'providerLocale' => $call->provider_target_locale !== $call->target_locale ? $call->provider_target_locale : null,
            'items' => number_format($call->item_count),
            'tokens' => ($call->input_tokens !== null ? number_format($call->input_tokens) : '—').' / '.($call->output_tokens !== null ? number_format($call->output_tokens) : '—'),
            'tokensUnknown' => $call->input_tokens === null || $call->output_tokens === null,
            'cost' => $call->estimated_cost_usd !== null ? self::rowMoney((string) $call->estimated_cost_usd) : '—',
            'costUnknown' => $call->estimated_cost_usd === null,
            'latency' => self::seconds($call->latency_ms),
            'latencyTone' => match (true) {
                $timeout !== null && $call->latency_ms >= $timeout * 1000 => 'danger',
                $call->latency_ms >= 10_000 => 'due',
                default => null,
            },
            'result' => match (true) {
                $failed => [
                    'kind' => 'failed',
                    'label' => (string) $call->error_code,
                    'detail' => ($call->http_status !== null ? "HTTP {$call->http_status} · " : '').self::count($call->item_count, 'item').' not translated',
                ],
                $call->failed_item_count > 0 => [
                    'kind' => 'partial',
                    'label' => number_format($call->succeeded_item_count).' of '.number_format($call->item_count).' translated',
                    'detail' => implode(', ', array_map(fn (string $code, int $count): string => "{$code} × {$count}", array_keys($failures), $failures)),
                ],
                default => ['kind' => 'success', 'label' => 'Success', 'detail' => null],
            },
        ];
    }

    // Configuration and guardrails ---------------------------------------------------------------

    /**
     * What translation runs with, as configured now. A value the application
     * cannot read for itself — the queue worker's own timeout, set where the
     * worker is started — is Not available rather than assumed; what is not
     * built yet is Not configured.
     *
     * @return list<array{label: string, value: string, note: ?string, muted: bool}>
     */
    private function configurationRows(TranslationAiConfiguration $configuration): array
    {
        $connection = config('queue.default');
        $connection = is_string($connection) ? $connection : null;
        $queue = $connection !== null ? config("queue.connections.{$connection}") : null;
        $queue = is_array($queue) ? $queue : [];
        $store = config('translation.bulk.cache_store');
        $driver = is_string($store) ? config("cache.stores.{$store}.driver") : null;
        $ttl = config('translation.bulk.ttl_seconds');
        $retention = config('translation.telemetry.retention_days');
        $jobTimeout = (new ReflectionProperty(GenerateProjectTranslationChunkJob::class, 'timeout'))->getDefaultValue();
        $jobTries = (new ReflectionProperty(GenerateProjectTranslationChunkJob::class, 'tries'))->getDefaultValue();
        $row = fn (string $label, ?string $value, ?string $note = null, string $missing = 'Not available'): array => ['label' => $label, 'value' => $value ?? $missing, 'note' => $note, 'muted' => $value === null];

        return [
            $row('Provider', $configuration->provider, 'translation.default', 'Not configured'),
            $row('Model', $configuration->model, 'translation.providers.*.model', 'Not configured'),
            $row(
                'Pricing',
                $configuration->inputUsdPerMillionTokens !== null && $configuration->outputUsdPerMillionTokens !== null
                    ? '$'.self::rate($configuration->inputUsdPerMillionTokens).' in · $'.self::rate($configuration->outputUsdPerMillionTokens).' out per 1M tokens'
                    : null,
                'translation.telemetry.pricing — no rates means an unknown cost',
                'Not configured',
            ),
            $row(
                'Provider timeout',
                $configuration->timeoutSeconds !== null ? self::wholeSeconds($configuration->timeoutSeconds).($configuration->connectTimeoutSeconds !== null ? ' · connect '.self::wholeSeconds($configuration->connectTimeoutSeconds) : '') : null,
                'Per request',
            ),
            $row('Translation job timeout', is_int($jobTimeout) ? self::wholeSeconds($jobTimeout) : null, 'Generate missing, per chunk'),
            $row('Queue', $connection !== null ? $connection.' · '.(is_string($queue['queue'] ?? null) ? $queue['queue'] : 'default') : null, 'queue.default'),
            $row('Queue retry_after', is_int($queue['retry_after'] ?? null) ? self::wholeSeconds($queue['retry_after']) : null, $connection !== null ? "queue.connections.{$connection}" : null),
            $row('Queue worker timeout', null, 'Set where the worker is started, which the application cannot read'),
            $row('Temporary results', is_string($store) ? $store.(is_string($driver) ? " store · {$driver}" : ' store') : null, 'translation.bulk.cache_store'),
            $row('Batch TTL', is_int($ttl) ? self::duration($ttl) : null, 'From when a generation starts; reading never extends it'),
            $row('Retry policy', is_int($jobTries) ? ($jobTries === 1 ? 'None · one attempt' : number_format($jobTries).' attempts') : null, 'A failed provider call is never sent again'),
            $row('Fallback provider', 'None', 'Items of a failed call fail; nothing is sent to another provider'),
            $row('Hourly limit', null, 'Not built yet', 'Not configured'),
            $row('Budget', null, 'Not built yet', 'Not configured'),
            $row('Telemetry retention', is_int($retention) ? self::count($retention, 'day') : null, 'translation.telemetry.retention_days'),
        ];
    }

    // Formatting -----------------------------------------------------------------------------

    private static function count(int $count, string $singular, ?string $plural = null): string
    {
        return number_format($count).' '.($count === 1 ? $singular : ($plural ?? Str::plural($singular)));
    }

    private static function calls(int $count): string
    {
        return self::count($count, 'call');
    }

    private static function percentage(int $part, int $whole): string
    {
        return number_format($part / $whole * 100, 1).'%';
    }

    private static function seconds(int $milliseconds): string
    {
        return number_format($milliseconds / 1000, 1).' s';
    }

    private static function wholeSeconds(int|float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 1), '0'), '.').' s';
    }

    private static function duration(int $seconds): string
    {
        return $seconds % 3600 === 0 ? self::count(intdiv($seconds, 3600), 'h', 'h') : self::count(intdiv($seconds, 60), 'min', 'min');
    }

    /** Tokens as the page's figures show them: 980, 1.2K, 12K, 1.25M. */
    private static function compact(int $count): string
    {
        return match (true) {
            $count >= 1_000_000 => number_format($count / 1_000_000, 2).'M',
            $count >= 10_000 => number_format($count / 1_000).'K',
            $count >= 1_000 => number_format($count / 1_000, 1).'K',
            default => (string) $count,
        };
    }

    /** A total in USD to the cent, or “< $0.01” for a cost too small to show in cents but not nothing. */
    private static function money(string $usd): string
    {
        $amount = BigDecimal::of($usd);

        if ($amount->isZero()) {
            return '$0.00';
        }

        return $amount->isLessThan('0.01') ? '< $0.01' : '$'.number_format((float) (string) $amount->toScale(2, RoundingMode::HalfUp), 2);
    }

    /**
     * One call's cost: to a hundredth of a cent, or to a millionth of a dollar
     * for a call cheaper than that — a single suggestion often is — and
     * “< $0.000001” below even that, which is still not nothing.
     */
    private static function rowMoney(string $usd): string
    {
        $amount = BigDecimal::of($usd);

        return match (true) {
            $amount->isZero() || $amount->isGreaterThanOrEqualTo('0.0001') => '$'.$amount->toScale(4, RoundingMode::HalfUp),
            $amount->isLessThan('0.000001') => '< $0.000001',
            default => '$'.$amount->toScale(6, RoundingMode::HalfUp),
        };
    }

    private static function rate(string $rate): string
    {
        $rate = rtrim(rtrim($rate, '0'), '.');

        return str_contains($rate, '.') && strlen(substr($rate, strpos($rate, '.') + 1)) < 2 ? number_format((float) $rate, 2) : $rate;
    }

    private static function day(CarbonImmutable $at, CarbonImmutable $now): string
    {
        return match (true) {
            $at->isSameDay($now) => 'Today',
            $at->isSameDay($now->subDay()) => 'Yesterday',
            default => $at->format('j M'),
        };
    }

    private static function moment(CarbonImmutable $at, CarbonImmutable $now): string
    {
        return ($at->isSameDay($now) ? 'today' : ($at->isSameDay($now->subDay()) ? 'yesterday' : $at->format('j M'))).' '.$at->format('H:i');
    }

    private static function ago(CarbonImmutable $at, CarbonImmutable $now): string
    {
        $seconds = max(0, $now->getTimestamp() - $at->getTimestamp());

        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => self::count(intdiv($seconds, 60), 'min', 'min').' ago',
            $seconds < 86400 => self::count(intdiv($seconds, 3600), 'h', 'h').' ago',
            default => self::count(intdiv($seconds, 86400), 'day').' ago',
        };
    }
}
