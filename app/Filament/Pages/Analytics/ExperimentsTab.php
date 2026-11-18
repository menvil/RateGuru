<?php

namespace App\Filament\Pages\Analytics;

use App\Enums\ComparisonEvidence;
use App\Enums\GameAudience;
use App\Enums\GameFlowStepType;
use App\Enums\GameStatus;
use App\Enums\PeriodMetric;
use App\Filament\Pages\Experiments\ExperimentsView;
use App\Filament\Pages\ExperimentsPage;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Queries\Games\ComparisonPeriodsQuery;
use App\Queries\Games\GameMetricsQuery;
use App\Support\Analytics\ActivityDay;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\ComparedVersion;
use App\Support\Analytics\ComparisonVerdict;
use App\Support\Analytics\PeriodComparison;
use App\Support\Analytics\SampleRatioCheck;
use App\Support\Traffic\Reports\TwoProportionTest;
use Carbon\CarbonImmutable;

/**
 * Analytics → Experiments, as the reference draws it: the latest traffic
 * periods that compare two versions or more to choose from, and the one
 * chosen — the address's, else the latest — with its game and audience,
 * whether it is still open, its days, shares, primary metric and visitors,
 * each version's content and steps, what the figures say so far, and every
 * metric of every version against the reference version with how sure the
 * difference is, the split checked against the shares, then the rates among
 * those who completed the game.
 *
 * The figures are the period's own visitors as they were assigned — the
 * population Growth → Experiments reads, through the same funnel and the
 * same comparison, so both say the same for the same period — and no filter
 * of the page applies to them. No comparison yet, and a period nobody was
 * sent to yet, each say so. Reads only.
 */
final class ExperimentsTab implements AnalyticsTab
{
    /** How many comparisons the chooser offers, the latest started first. */
    public const int CHOICES = 12;

    public const string CAPTION = 'Uses the population assigned to each version. Page filters don’t apply.';

    /** @var list<PeriodMetric> the table's rows after Assigned, in the reference's order, the goal last */
    private const array ROWS = [
        PeriodMetric::Starts,
        PeriodMetric::Completions,
        PeriodMetric::ResultViewed,
        PeriodMetric::Emails,
        PeriodMetric::Registrations,
        PeriodMetric::Activated,
        PeriodMetric::Goal,
    ];

    /** @var array<string, string> the badge tone of each kind of evidence */
    private const array EVIDENCE_TONES = ['by_design' => 'outline', 'too_early' => 'neutral', 'strong' => 'success', 'ahead' => 'warning', 'behind' => 'warning', 'none' => 'neutral'];

    /** @var array<string, array{tone: string, icon: string}> how the strip above the table draws each verdict */
    private const array VERDICTS = [
        ComparisonVerdict::WON => ['tone' => 'success', 'icon' => 'circle-check'],
        ComparisonVerdict::PAUSED => ['tone' => 'warning', 'icon' => 'circle-pause'],
        ComparisonVerdict::RUNNING => ['tone' => 'warning', 'icon' => 'hourglass'],
        ComparisonVerdict::ENDED => ['tone' => 'neutral', 'icon' => 'scale'],
    ];

    public function __construct(
        private readonly ComparisonPeriodsQuery $periods,
        private readonly GameMetricsQuery $metrics,
    ) {}

    /** @return array<string, mixed> */
    public function data(GrowthAnalyticsPage $page, AnalyticsFilter $filter): array
    {
        $today = CarbonImmutable::now('UTC');
        $latest = $this->periods->latestComparisons(self::CHOICES);
        $asked = is_numeric($page->exp) ? (int) $page->exp : 0;
        $chosen = $latest->first(fn (GameTrafficPeriod $period): bool => (int) $period->id === $asked);

        if ($chosen === null && $asked > 0) {
            $chosen = $this->periods->comparison($asked);

            if ($chosen !== null) {
                $latest->push($chosen);
            }
        }

        $chosen ??= $latest->first();
        $allUrl = ExperimentsPage::getUrl(['show' => 'comparisons']);

        if ($chosen === null) {
            return ['empty' => true, 'caption' => self::CAPTION, 'allUrl' => $allUrl];
        }

        $chosen->loadMissing(['versions.flow', 'versions.contentSet']);

        return [
            'empty' => false,
            'caption' => self::CAPTION,
            'allUrl' => $allUrl,
            'choices' => $latest->map(fn (GameTrafficPeriod $period): array => [
                'id' => (int) $period->id,
                // Periods of one game differ by audience: the chip says which, as the title does.
                'name' => $period->game->title.' · '.$period->audience->label(),
                'audience' => $period->audience->label(),
                'icon' => ExperimentsView::AUDIENCE_ICONS[$period->audience->value],
                'meta' => self::when($period, $today, withStatus: true),
                'chosen' => (int) $period->id === (int) $chosen->id,
            ])->values()->all(),
            ...$this->chosen($chosen, $today),
        ];
    }

    /**
     * The chosen comparison: its header, its versions, its verdict and its
     * table — or, while nobody was sent to a version, what says so.
     *
     * @return array<string, mixed>
     */
    private function chosen(GameTrafficPeriod $period, CarbonImmutable $today): array
    {
        $id = (int) $period->id;
        $comparison = ExperimentsView::comparison($period, $period->audience, $this->metrics->periodFunnel([$id], ExperimentsView::maturedBefore())[$id] ?? []);
        $status = self::status($period);
        $ended = $status === 'ended';
        $assigned = $comparison->assigned();
        $reference = $comparison->reference();
        $verdict = ComparisonVerdict::of($comparison, $ended, $status === 'paused', self::when($period, $today));
        $fresh = $assigned === 0;

        return [
            'periodId' => $id,
            'title' => implode(' vs ', array_map(fn (ComparedVersion $version): string => $version->label(), $comparison->versions)),
            'subtitle' => $period->game->title.' · '.$period->audience->label(),
            'status' => match ($status) {
                'current' => ['label' => 'Current', 'tone' => 'success', 'dot' => true],
                'paused' => ['label' => 'Paused', 'tone' => 'warning', 'dot' => false],
                default => ['label' => 'Ended', 'tone' => 'neutral', 'dot' => false],
            },
            'facts' => [
                ['label' => 'Duration', 'value' => $ended ? ExperimentsView::dates($period, true, $today) : self::when($period, $today)],
                ['label' => 'Traffic', 'value' => ExperimentsView::mix($period)],
                ['label' => 'Primary metric', 'value' => self::label($comparison->primary())],
                ['label' => 'Assigned', 'value' => number_format($assigned)],
            ],
            'variants' => array_map(fn (ComparedVersion $version): array => self::variant($period, $comparison, $version, $reference), $comparison->versions),
            // A period nobody was sent to says so once, below — why, for a paused game, too.
            'verdict' => $fresh ? null : [
                ...self::VERDICTS[$verdict->kind],
                'kind' => $verdict->kind,
                'title' => $verdict->title,
                'text' => $verdict->text,
            ],
            'fresh' => ! $fresh ? null : [
                'title' => 'No visitors yet',
                'text' => match ($status) {
                    'ended' => 'Nobody was sent to a version in this period.',
                    'paused' => 'Nobody was sent to a version before the game was paused. Numbers appear once it is live again.',
                    default => 'This period just started. Numbers appear as visitors arrive.',
                },
            ],
            'table' => $fresh || $reference === null ? null : self::table($comparison, $reference, self::gated($period, $comparison)),
        ];
    }

    /**
     * The table: the reference's column, then for every other version its
     * rate, its change against the reference and how sure that is.
     *
     * @return array<string, mixed>
     */
    private static function table(PeriodComparison $comparison, ComparedVersion $reference, ?string $gated): array
    {
        $others = array_values(array_filter($comparison->versions, fn (ComparedVersion $version): bool => $version->id !== $reference->id));
        $primary = $comparison->primary();
        $rows = [self::assignedRow($comparison, $reference, $others)];

        foreach (self::ROWS as $metric) {
            $rows[] = [
                'label' => self::label($metric),
                'sub' => self::sub($comparison, $metric, [$reference, ...$others], $gated),
                'primary' => $metric === $primary,
                'reference' => self::cell($comparison, $reference, $metric),
                'groups' => array_map(function (ComparedVersion $version) use ($comparison, $metric): array {
                    $evidence = $comparison->evidence($version, $metric);

                    return [
                        ...self::cell($comparison, $version, $metric),
                        'change' => ComparisonVerdict::change($comparison->relative($version, $metric)),
                        'evidence' => $evidence instanceof ComparisonEvidence ? ['label' => $evidence->label(), 'tone' => self::EVIDENCE_TONES[$evidence->value]] : null,
                    ];
                }, $others),
            ];
        }

        $conditional = fn (string $label, PeriodMetric $metric, string $note): array => [
            'label' => $label,
            'note' => $note,
            'reference' => self::percent($comparison->conditional($reference, $metric, PeriodMetric::Completions)),
            'groups' => array_map(fn (ComparedVersion $version): array => [
                'rate' => self::percent($comparison->conditional($version, $metric, PeriodMetric::Completions)),
                'change' => ComparisonVerdict::change($comparison->conditionalRelative($version, $metric, PeriodMetric::Completions)),
            ], $others),
        ];
        $asking = self::asking($comparison);

        return [
            'heads' => [
                'reference' => $reference->label().' · Reference',
                'groups' => array_map(fn (ComparedVersion $version): array => ['version' => $version->label(), 'change' => $version->label().' vs '.$reference->label()], $others),
            ],
            // The metric column — narrower on a phone — the reference's, then a rate, a change and evidence for every other version.
            'columns' => '--rg-admin-table-columns: minmax(var(--rg-admin-experiments-metric-width), 1.4fr) minmax(120px, 1fr) repeat('.count($others).', minmax(112px, 1fr) 88px 160px); '
                .'--rg-admin-table-min-width: calc(var(--rg-admin-experiments-metric-width) + '.(120 + 360 * count($others)).'px)',
            'rows' => $rows,
            // Signed-in players make no account and leave no email: nothing to show for them.
            'conditional' => $comparison->audience !== GameAudience::Anonymous ? [] : [
                $conditional('Accounts among completers', PeriodMetric::Registrations, 'Conditional: hides drop-off before the result'),
                $conditional('Emails among completers', PeriodMetric::Emails, $asking === null ? '' : 'Only '.$asking.' '.(str_contains($asking, ' and ') ? 'have' : 'has').' an email step'),
            ],
            'foot' => 'Rates use every visitor assigned to each version; changes are relative to '.$reference->label().', the version with the largest share. '
                .'Evidence comes from a two-proportion test: “Strong evidence” from 95% confidence, “Ahead, not sure yet” or “Behind, not sure yet” from 80%, “Too early” while either side has fewer than '.TwoProportionTest::MIN_ACCOUNTS.' conversions on that row. '
                .'Activation counts only accounts that signed up 8 or more days ago. The verdict follows '.$comparison->primaryNoun().'.',
        ];
    }

    /**
     * The first row: the visitors each version was assigned, the shares
     * against the split that came of them, and whether the split looks off.
     *
     * @param  list<ComparedVersion>  $others
     * @return array<string, mixed>
     */
    private static function assignedRow(PeriodComparison $comparison, ComparedVersion $reference, array $others): array
    {
        $assigned = $comparison->assigned();
        // In the columns' order: the reference, then the others.
        $columns = [$reference, ...$others];
        $check = new SampleRatioCheck(
            array_map(fn (ComparedVersion $version): int => $comparison->funnel($version)->assigned, $columns),
            array_map(fn (ComparedVersion $version): int => $version->share, $columns),
        );
        $split = 'Split '.implode('/', array_map(fn (ComparedVersion $version): int => $version->share, $columns));

        if ($assigned > 0) {
            $split .= ' · '.implode(' · ', array_map(fn (ComparedVersion $version): string => number_format($comparison->funnel($version)->assigned * 100 / $assigned, 1).'%', $columns));
        }

        return [
            'label' => 'Assigned visitors',
            'sub' => $split,
            'primary' => false,
            'reference' => ['rate' => number_format($comparison->funnel($reference)->assigned), 'count' => '', 'none' => false],
            'groups' => array_map(fn (ComparedVersion $version, int $index): array => [
                'rate' => number_format($comparison->funnel($version)->assigned),
                'count' => '',
                'none' => false,
                'change' => '—',
                // The split is the period's as a whole: said once.
                'evidence' => $index === 0 ? ['label' => $check->label(), 'tone' => $check->tone()] : null,
            ], $others, array_keys($others)),
        ];
    }

    /**
     * One version's figure of a metric: its rate, and under it how many of
     * how many — or why it can't be read.
     *
     * @return array{rate: string, count: string, none: bool}
     */
    private static function cell(PeriodComparison $comparison, ComparedVersion $version, PeriodMetric $metric): array
    {
        $rate = $comparison->rate($version, $metric);

        if ($rate === null) {
            return ['rate' => '—', 'count' => $comparison->dash($version, $metric) ?? '—', 'none' => true];
        }

        $funnel = $comparison->funnel($version);

        // Rounded as Growth → Experiments rounds it, so both say the same.
        return ['rate' => number_format($rate, 1).'%', 'count' => number_format($funnel->count($metric)).' of '.number_format($funnel->assigned), 'none' => false];
    }

    /**
     * What a metric's row says under its name, any figure of each version
     * in the columns' order; empty for nothing.
     *
     * @param  list<ComparedVersion>  $columns
     */
    private static function sub(PeriodComparison $comparison, PeriodMetric $metric, array $columns, ?string $gated = null): string
    {
        $anonymous = $comparison->audience === GameAudience::Anonymous;
        $asking = $metric === PeriodMetric::Emails ? self::asking($comparison) : null;

        return match (true) {
            $asking !== null => 'Only '.$asking.' '.(str_contains($asking, ' and ') ? 'ask' : 'asks').' for one',
            $metric === PeriodMetric::ResultViewed && $gated !== null => $gated.' '.(str_contains($gated, ' and ') ? 'show' : 'shows').' it after the email step',
            $metric === PeriodMetric::Registrations && $anonymous => 'Confirmed new accounts',
            $metric === PeriodMetric::Activated && $anonymous => 'Mature accounts: '.implode(' · ', array_map(fn (ComparedVersion $version): string => number_format($comparison->funnel($version)->matured), $columns)).' (signed up 8+ days ago)',
            default => '',
        };
    }

    /**
     * The versions that show an anonymous visitor the full result only after
     * the email step, in words — “V8”, “V8 and V9” — where some do and some
     * don't; null otherwise.
     */
    private static function gated(GameTrafficPeriod $period, PeriodComparison $comparison): ?string
    {
        if ($comparison->audience !== GameAudience::Anonymous) {
            return null;
        }

        $gated = [];

        foreach ($comparison->versions as $version) {
            $model = $period->versions->firstWhere('id', $version->id);
            $types = $model instanceof GameVersion
                ? array_values(array_map(fn ($step): GameFlowStepType => $step->type, array_filter($model->read()->steps, fn ($step): bool => $step->audience->includes($period->audience))))
                : [];
            $email = array_search(GameFlowStepType::EmailCapture, $types, true);
            $result = array_search(GameFlowStepType::FullResult, $types, true);

            if ($email !== false && ($result === false || $email < $result)) {
                $gated[] = $version->label();
            }
        }

        if ($gated === [] || count($gated) === count($comparison->versions)) {
            return null;
        }

        $last = array_pop($gated);

        return $gated === [] ? $last : implode(', ', $gated).' and '.$last;
    }

    /**
     * The versions that ask an anonymous visitor for an email, in words —
     * “V8”, “V8 and V9” — where some do and some don't; null otherwise.
     */
    private static function asking(PeriodComparison $comparison): ?string
    {
        if ($comparison->audience !== GameAudience::Anonymous) {
            return null;
        }

        $asking = array_values(array_map(fn (ComparedVersion $version): string => $version->label(), array_filter($comparison->versions, fn (ComparedVersion $version): bool => $version->asksForEmail)));

        if ($asking === [] || count($asking) === count($comparison->versions)) {
            return null;
        }

        $last = array_pop($asking);

        return $asking === [] ? $last : implode(', ', $asking).' and '.$last;
    }

    /**
     * A version's panel: its number, content and flow, share and visitors,
     * whether it is the reference, and the steps its visitors meet.
     *
     * @return array<string, mixed>
     */
    private static function variant(GameTrafficPeriod $period, PeriodComparison $comparison, ComparedVersion $version, ?ComparedVersion $reference): array
    {
        $model = $period->versions->firstWhere('id', $version->id);
        $steps = $model instanceof GameVersion
            ? array_values(array_filter($model->read()->steps, fn ($step): bool => $step->audience->includes($period->audience)))
            : [];

        return [
            'key' => $version->label(),
            'name' => ($model?->contentSet->name ?? '—').' + '.($model?->flow->name ?? '—'),
            'meta' => $version->share.'% share · '.number_format($comparison->funnel($version)->assigned).' assigned',
            'reference' => $reference?->id === $version->id,
            'steps' => array_map(fn ($step): array => [
                'label' => $step->type->label(),
                'kind' => match ($step->type) {
                    GameFlowStepType::Game => 'game',
                    GameFlowStepType::ResultPreview, GameFlowStepType::EmailCapture => 'gate',
                    default => 'step',
                },
            ], $steps),
        ];
    }

    /** A metric as the tab names it. */
    private static function label(PeriodMetric $metric): string
    {
        return match ($metric) {
            PeriodMetric::Starts => 'Started the game',
            PeriodMetric::Completions => 'Completed the game',
            PeriodMetric::ResultViewed => 'Saw the full result',
            PeriodMetric::Emails => 'Left an email',
            PeriodMetric::Registrations => 'Created an account',
            PeriodMetric::Activated => 'Activated within 7 days',
            PeriodMetric::Goal => 'Reached the goal',
        };
    }

    /** Whether a period is open, open while its game is paused, or over. */
    private static function status(GameTrafficPeriod $period): string
    {
        return match (true) {
            $period->ended_at !== null => 'ended',
            $period->game->status === GameStatus::Paused => 'paused',
            default => 'current',
        };
    }

    /**
     * Where a period is in its days: “Day 8” while it is open — its first
     * day being day 1 — and the day it ended once over, without this year;
     * with its status before, for the chooser: “Current · day 8”, “Paused ·
     * day 8”, “Ended 22 Sep”.
     */
    private static function when(GameTrafficPeriod $period, CarbonImmutable $today, bool $withStatus = false): string
    {
        if ($period->ended_at !== null) {
            $ended = $period->ended_at->utc();
            $day = $ended->format($ended->year === $today->year ? 'd M' : 'd M Y');

            return $withStatus ? 'Ended '.$day : $day;
        }

        $day = max(1, ActivityDay::number($period->started_at, $today) + 1);

        return $withStatus ? (self::status($period) === 'paused' ? 'Paused' : 'Current').' · day '.$day : 'Day '.$day;
    }

    private static function percent(?float $rate): string
    {
        return $rate === null ? '—' : number_format($rate, 1).'%';
    }
}
