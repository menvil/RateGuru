<?php

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
    ];

    $list = SiteZoneList::of($rows, 'all', '', 0, 'spend', 'desc', 1);

    expect($list['counts'])->toBe(['all' => 7, 'block' => 3, 'profit' => 1, 'watch' => 1, 'early' => 1, 'blocked' => 1])
        ->and(SiteZoneList::of($rows, 'block', '', 0, 'spend', 'desc', 1)['matching'])->toBe(['1:site:2', '1:site:1', '1:site:3'])
        ->and(SiteZoneList::of($rows, 'all', '', 100, 'spend', 'desc', 1))->counts->toMatchArray(['all' => 3])->hidden->toBe(4);
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
