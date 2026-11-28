<?php

namespace App\Support\Traffic\Reports;

use App\Support\Traffic\Money\TrafficMoney;

/**
 * The networks' sites or zones as one flat list, as Sites & zones draws it:
 * each in a segment by its block rule state — worth blocking (bots, losing,
 * a weak start, paid impressions that mostly never reach RateGuru),
 * profitable, below the line, too early, blocked — counted per segment, and
 * every one not blocked counted as active;
 * searched by its ID, its network or its campaign; those under so many
 * clicks left out, but for Low reach, which the paid impressions judge
 * (clears()); sorted by a column, its key last so two equal rows never
 * trade places — a row the network reported no impressions for after every
 * one it did, by those columns; and cut into pages, with the total of every
 * page, its impressions those of the rows that have them.
 */
final class SiteZoneList
{
    public const int PER_PAGE = 25;

    /** @var array<string, string> segment => label */
    public const array SEGMENTS = [
        'all' => 'All',
        // Every one not blocked: All without the Blocked segment.
        'active' => 'Active',
        'block' => 'Worth blocking',
        'profit' => 'Profitable',
        'watch' => 'Below the line',
        'early' => 'Too early',
        'blocked' => 'Blocked',
    ];

    /** @var list<string> the states Worth blocking takes: the block rule's verdicts, and the hint that paid impressions mostly never arrive */
    public const array WORTH_BLOCKING = ['bots', 'losing', 'weak_start', 'low_reach'];

    /** @var list<string> */
    /** @var array<string, string> a share a row can be kept by, as the filter names it: what of what the column's share is */
    public const array SHARES = [
        'reach' => 'Reached (of paid impressions)',
        'opened' => 'Opened (of visits)',
        'started' => 'Started (of opened)',
        'finished' => 'Finished (of started)',
        'accounts' => 'Accounts (of finished)',  // shown under Accounts unless a post was published, which shows instead
        'flagged' => 'Flagged (of visits)',
        'repeat' => 'Repeat address (of those on record)',
    ];

    public const array SORTS = ['impressions', 'reach', 'visits', 'opened', 'started', 'finished', 'accounts', 'spend', 'value', 'profit', 'roi', 'cpv', 'vpv', 'cpa', 'flagged', 'repeat'];

    /**
     * @template TRow of array{key: string, ref: string, source: string, campaign: string|null, state: string, blocked: bool, figures: ReportFigures, reach?: ReachFigures|null}
     *
     * @param  list<TRow>  $rows
     * @param  array{column: string, side: 'above'|'below', percent: float}|null  $share  keep only rows whose share in a column (SHARES) is above or below so many percent; a row with no share there is left out
     * @return array{rows: list<TRow>, counts: array<string, int>, matching: list<string>, total: ReportFigures, reach: ReachFigures|null, pages: int, page: int, hidden: int}
     */
    public static function of(array $rows, string $segment, string $search, int $minVisits, string $sort, string $direction, int $page, ?array $share = null): array
    {
        $search = mb_strtolower(trim($search));
        $visible = array_values(array_filter($rows, fn (array $row): bool => self::clears($row, $minVisits)
            && ($search === '' || str_contains(mb_strtolower($row['ref'].' '.$row['source'].' '.(string) $row['campaign']), $search))
            && ($share === null || self::sharePasses($row, $share))));
        $hidden = count($rows) - count($visible);
        $counts = array_fill_keys(array_keys(self::SEGMENTS), 0);

        foreach ($visible as $row) {
            $counts['all']++;
            $counts['active'] += $row['blocked'] ? 0 : 1;
            $counts[self::segmentOf($row)]++;
        }

        $matched = array_values(array_filter($visible, fn (array $row): bool => match ($segment) {
            'all' => true,
            'active' => ! $row['blocked'],
            default => self::segmentOf($row) === $segment,
        }));
        $sign = $direction === 'asc' ? 1 : -1;
        $order = array_map(function (array $row) use ($sort, $sign): array {
            $value = self::value($row, $sort);

            return [$value === null ? 1 : 0, ($value ?? 0.0) * $sign, $row['key']];
        }, $matched);
        $places = array_keys($matched);
        usort($places, fn (int $a, int $b): int => $order[$a] <=> $order[$b]);
        $matched = array_map(fn (int $place): array => $matched[$place], $places);
        $reached = array_values(array_filter(array_column($matched, 'reach')));

        $pages = max(1, (int) ceil(count($matched) / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        return [
            'rows' => array_slice($matched, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'counts' => $counts,
            'matching' => array_column($matched, 'key'),
            'total' => array_reduce($matched, fn (ReportFigures $sum, array $row): ReportFigures => $sum->plus($row['figures']), new ReportFigures),
            'reach' => $reached === [] ? null : array_reduce($reached, fn (ReachFigures $sum, ReachFigures $reach): ReachFigures => $sum->plus($reach), new ReachFigures),
            'pages' => $pages,
            'page' => $page,
            'hidden' => $hidden,
        ];
    }

    /**
     * Whether a row stays in the list past the clicks threshold: so many
     * clicks, or Low reach not blocked yet — judged by the paid impressions
     * of closed days, not by its clicks, it shows however few it has, none
     * at all for a place the network billed that never sent a visit.
     *
     * @param  array{state: string, blocked: bool, figures: ReportFigures}  $row
     */
    public static function clears(array $row, int $minVisits): bool
    {
        return $row['figures']->visits >= $minVisits || ($row['state'] === 'low_reach' && ! $row['blocked']);
    }

    /**
     * A row's segment: a site losing money while the rule still waits after
     * its first click is below the line, not worth blocking yet.
     *
     * @param  array{state: string, blocked: bool}  $row
     */
    public static function segmentOf(array $row): string
    {
        return match (true) {
            $row['blocked'] => 'blocked',
            in_array($row['state'], self::WORTH_BLOCKING, true) => 'block',
            in_array($row['state'], ['profit', 'watch'], true) => $row['state'],
            $row['state'] === 'waiting' => 'watch',
            default => 'early',
        };
    }

    /**
     * Whether a row's share in a column is above or below the percent: the
     * share the column shows under its figure, rounded to a tenth of a percent
     * as it shows, so a row reading 2.0% is on neither side of 2. A row with
     * nothing to take a share of there is on neither side.
     *
     * @param  array{figures: ReportFigures, reach?: ReachFigures|null}  $row
     * @param  array{column: string, side: 'above'|'below', percent: float}  $share
     */
    public static function sharePasses(array $row, array $share): bool
    {
        $rate = self::share($row, $share['column']);

        if ($rate === null) {
            return false;
        }

        $percent = round($rate * 100, 1);

        return $share['side'] === 'above' ? $percent > $share['percent'] : $percent < $share['percent'];
    }

    /**
     * A row's share in a column, 0–1, as the column shows it under its
     * figure; null where there is nothing to take it of.
     *
     * @param  array{figures: ReportFigures, reach?: ReachFigures|null}  $row
     */
    public static function share(array $row, string $column): ?float
    {
        $figures = $row['figures'];

        return match ($column) {
            'reach' => ($row['reach'] ?? null)?->reachRate(),
            'opened' => $figures->openRate(),
            'started' => $figures->startRate(),
            'finished' => $figures->started === 0 ? null : $figures->completed / $figures->started,
            'accounts' => $figures->completed === 0 ? null : $figures->accounts / $figures->completed,
            'flagged' => $figures->flaggedRate(),
            'repeat' => $figures->repeatAddressRate(),
            default => null,
        };
    }

    /**
     * A row's value in a column; null for impressions where the network
     * reported none for it.
     *
     * @param  array{figures: ReportFigures, reach?: ReachFigures|null}  $row
     */
    private static function value(array $row, string $sort): ?float
    {
        $figures = $row['figures'];
        $spend = (float) (string) TrafficMoney::of($figures->spend);
        $value = (float) (string) $figures->value();
        $reach = $row['reach'] ?? null;

        return match ($sort) {
            'impressions' => $reach === null ? null : (float) $reach->impressions,
            'reach' => $reach?->reachRate(),
            'visits' => (float) $figures->visits,
            'opened' => (float) $figures->opened,
            'started' => (float) $figures->started,
            'finished' => (float) $figures->completed,
            'accounts' => (float) $figures->accounts,
            'value' => $value,
            'profit' => $value - $spend,
            'roi' => $figures->roi() ?? -1e9,
            'cpv' => $figures->visits === 0 ? -1.0 : $spend / $figures->visits,
            'vpv' => $figures->visits === 0 ? -1.0 : $value / $figures->visits,
            'cpa' => $figures->costPerAccount() === null ? -1e8 : (float) $figures->costPerAccount(),
            'flagged' => $figures->flaggedRate() ?? -1.0,
            'repeat' => $figures->repeatAddressRate() ?? -1.0,
            default => $spend,
        };
    }
}
