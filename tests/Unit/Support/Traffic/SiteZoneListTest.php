<?php

use App\Support\Traffic\Reports\ReachFigures;
use App\Support\Traffic\Reports\ReportFigures;
use App\Support\Traffic\Reports\SiteZoneList;

/** A site of a network for the list: its state by the block rule, whether it is blocked, and its figures. */
function siteRow(string $ref, string $state, int $visits, string $spend, bool $blocked = false, ?string $campaign = null): array
{
    return [
        'key' => '1:site:'.$ref,
        'ref' => $ref,
        'source' => 'PopAds main',
        'campaign' => $campaign,
        'state' => $state,
        'blocked' => $blocked,
        'figures' => new ReportFigures(visits: $visits, opened: $visits, spend: $spend),
    ];
}

it('puts each site in its segment and counts the segments over what is visible', function () {
    $rows = [
        siteRow('1', 'bots', 40, '1'),
        siteRow('2', 'losing', 300, '3'),
        siteRow('3', 'weak_start', 70, '0.5'),
        siteRow('4', 'profit', 250, '2'),
        siteRow('5', 'watch', 210, '2'),
        siteRow('6', 'early', 5, '0.05'),
        siteRow('7', 'bots', 50, '1', blocked: true),
        siteRow('8', 'low_reach', 90, '0.2'),
    ];

    $list = SiteZoneList::of($rows, 'all', '', 0, 'spend', 'desc', 1);

    expect($list['counts'])->toBe(['all' => 8, 'block' => 4, 'profit' => 1, 'watch' => 1, 'early' => 1, 'blocked' => 1])
        ->and(SiteZoneList::of($rows, 'block', '', 0, 'spend', 'desc', 1)['matching'])->toBe(['1:site:2', '1:site:1', '1:site:3', '1:site:8'])
        ->and(SiteZoneList::of($rows, 'all', '', 100, 'spend', 'desc', 1))->counts->toMatchArray(['all' => 3])->hidden->toBe(5);
});

it('finds a site by its ID, its network or its campaign', function () {
    $rows = [siteRow('40123', 'early', 1, '0'), siteRow('50001', 'early', 1, '0', campaign: 'US popunder')];

    expect(SiteZoneList::of($rows, 'all', '401', 0, 'spend', 'desc', 1)['matching'])->toBe(['1:site:40123'])
        ->and(SiteZoneList::of($rows, 'all', 'popunder', 0, 'spend', 'desc', 1)['matching'])->toBe(['1:site:50001'])
        ->and(SiteZoneList::of($rows, 'all', 'POPADS', 0, 'spend', 'desc', 1)['matching'])->toHaveCount(2);
});

it('sorts by any column with the key last, so equal rows keep their places, and pages by 25 with the total of every page', function () {
    $rows = array_map(fn (int $i): array => siteRow((string) $i, 'early', $i % 3, '0.01'), range(1, 30));

    $first = SiteZoneList::of($rows, 'all', '', 0, 'visits', 'desc', 1);
    $last = SiteZoneList::of($rows, 'all', '', 0, 'visits', 'desc', 9);

    expect($first['rows'])->toHaveCount(25)
        ->and($first['rows'][0]['figures']->visits)->toBe(2)
        ->and(array_slice(array_column($first['rows'], 'key'), 0, 6))->toBe(['1:site:11', '1:site:14', '1:site:17', '1:site:2', '1:site:20', '1:site:23'])
        ->and(array_column(SiteZoneList::of(array_reverse($rows), 'all', '', 0, 'visits', 'desc', 1)['rows'], 'key'))->toBe(array_column($first['rows'], 'key'))
        ->and($last['page'])->toBe(2)
        ->and($last['rows'])->toHaveCount(5)
        ->and($first['pages'])->toBe(2)
        ->and($first['total']->visits)->toBe(array_sum(array_map(fn (array $row): int => $row['figures']->visits, $rows)))
        ->and($first['total']->spend)->toBe('0.30000000');
});

it('sorts by the impressions paid and the share that reached RateGuru, the rows the network reported nothing for last either way, and totals the impressions of those that have them', function () {
    $rows = [
        [...siteRow('1', 'early', 10, '0.1'), 'reach' => new ReachFigures(impressions: 900, amount: '0.9', reached: 450)],
        [...siteRow('2', 'early', 10, '0.1'), 'reach' => null],
        [...siteRow('3', 'early', 10, '0.1'), 'reach' => new ReachFigures(impressions: 300, amount: '0.3', reached: 270)],
        [...siteRow('4', 'early', 10, '0.1'), 'reach' => new ReachFigures(impressions: 600, amount: '0.6', reached: 420)],
    ];
    $keys = fn (string $sort, string $direction): array => SiteZoneList::of($rows, 'all', '', 0, $sort, $direction, 1)['matching'];
    $total = SiteZoneList::of($rows, 'all', '', 0, 'spend', 'desc', 1)['reach'];

    expect($keys('impressions', 'desc'))->toBe(['1:site:1', '1:site:4', '1:site:3', '1:site:2'])
        ->and($keys('impressions', 'asc'))->toBe(['1:site:3', '1:site:4', '1:site:1', '1:site:2'])
        ->and($keys('reach', 'desc'))->toBe(['1:site:3', '1:site:4', '1:site:1', '1:site:2'])
        ->and($keys('reach', 'asc'))->toBe(['1:site:1', '1:site:4', '1:site:3', '1:site:2'])
        ->and([$total->impressions, $total->reached])->toBe([1800, 1140])
        ->and(SiteZoneList::of([siteRow('5', 'early', 1, '0')], 'all', '', 0, 'spend', 'desc', 1)['reach'])->toBeNull();
});
