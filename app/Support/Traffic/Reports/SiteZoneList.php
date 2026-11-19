<?php

namespace App\Support\Traffic\Reports;

use App\Support\Traffic\Money\TrafficMoney;

/**
 * The networks' sites or zones as one flat list, as Sites & zones draws it:
 * each in a segment by its block rule state — worth blocking (bots, losing,
 * a weak start, paid impressions that mostly never reach RateGuru),
 * profitable, below the line, too early, blocked — counted per segment;
 * searched by its ID, its network or its campaign; those under so many
 * clicks left out; sorted by a column, its key last so two equal rows never
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
        'block' => 'Worth blocking',
        'profit' => 'Profitable',
        'watch' => 'Below the line',
        'early' => 'Too early',
        'blocked' => 'Blocked',
    ];

    /** @var list<string> the states Worth blocking takes: the block rule's verdicts, and the hint that paid impressions mostly never arrive */
    public const array WORTH_BLOCKING = ['bots', 'losing', 'weak_start', 'low_reach'];

    /** @var list<string> */
    public const array SORTS = ['impressions', 'reach', 'visits', 'opened', 'started', 'finished', 'accounts', 'spend', 'value', 'profit', 'roi', 'cpv', 'vpv', 'cpa'];

    /**
     * @template TRow of array{key: string, ref: string, source: string, campaign: string|null, state: string, blocked: bool, figures: ReportFigures, reach?: ReachFigures|null}
     *
     * @param  list<TRow>  $rows
     * @return array{rows: list<TRow>, counts: array<string, int>, matching: list<string>, total: ReportFigures, reach: ReachFigures|null, pages: int, page: int, hidden: int}
     */
    public static function of(array $rows, string $segment, string $search, int $minVisits, string $sort, string $direction, int $page): array
    {
        $search = mb_strtolower(trim($search));
        $visible = array_values(array_filter($rows, fn (array $row): bool => $row['figures']->visits >= $minVisits
            && ($search === '' || str_contains(mb_strtolower($row['ref'].' '.$row['source'].' '.(string) $row['campaign']), $search))));
        $hidden = count($rows) - count($visible);
        $counts = array_fill_keys(array_keys(self::SEGMENTS), 0);

        foreach ($visible as $row) {
            $counts['all']++;
            $counts[self::segmentOf($row)]++;
        }

        $matched = array_values(array_filter($visible, fn (array $row): bool => $segment === 'all' || self::segmentOf($row) === $segment));
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
            default => $spend,
        };
    }
}
