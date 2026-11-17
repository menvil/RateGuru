<?php

namespace App\Support\Traffic\Reports;

use App\Support\Traffic\Money\TrafficMoney;

/**
 * The networks' sites or zones as one flat list, as Sites & zones draws it:
 * each in a segment by its block rule state — worth blocking (bots, losing,
 * a weak start), profitable, below the line, too early, blocked — counted
 * per segment; searched by its ID, its network or its campaign; those under
 * so many clicks left out; sorted by a column, its key last so two equal
 * rows never trade places; and cut into pages, with the total of every page.
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

    /** @var list<string> */
    public const array SORTS = ['visits', 'opened', 'started', 'finished', 'accounts', 'spend', 'value', 'profit', 'roi', 'cpv', 'vpv', 'cpa'];

    /**
     * @template TRow of array{key: string, ref: string, source: string, campaign: string|null, state: string, blocked: bool, figures: ReportFigures}
     *
     * @param  list<TRow>  $rows
     * @return array{rows: list<TRow>, counts: array<string, int>, matching: list<string>, total: ReportFigures, pages: int, page: int, hidden: int}
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
        usort($matched, fn (array $a, array $b): int => [self::value($a['figures'], $sort) * $sign, $a['key']] <=> [self::value($b['figures'], $sort) * $sign, $b['key']]);

        $pages = max(1, (int) ceil(count($matched) / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        return [
            'rows' => array_slice($matched, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'counts' => $counts,
            'matching' => array_column($matched, 'key'),
            'total' => array_reduce($matched, fn (ReportFigures $sum, array $row): ReportFigures => $sum->plus($row['figures']), new ReportFigures),
            'pages' => $pages,
            'page' => $page,
            'hidden' => $hidden,
        ];
    }

    /** @param  array{state: string, blocked: bool}  $row */
    public static function segmentOf(array $row): string
    {
        return match (true) {
            $row['blocked'] => 'blocked',
            in_array($row['state'], ['bots', 'losing', 'weak_start'], true) => 'block',
            in_array($row['state'], ['profit', 'watch'], true) => $row['state'],
            default => 'early',
        };
    }

    private static function value(ReportFigures $figures, string $sort): float
    {
        $spend = (float) (string) TrafficMoney::of($figures->spend);
        $value = (float) (string) $figures->value();

        return match ($sort) {
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
