<?php

use App\Enums\TrafficCostUnit;
use App\Enums\TrafficFixedPeriod;
use App\Enums\TrafficSpendBasis;
use App\Enums\TrafficSpendMode;
use App\Support\Traffic\Spend\ClickSpend;
use App\Support\Traffic\Spend\FixedPriceSchedule;
use App\Support\Traffic\Spend\SpendAllocator;
use App\Support\Traffic\Spend\SpendCsv;
use Brick\Math\BigDecimal;

it('takes a click to cost what its campaign or source counts', function (TrafficSpendMode $mode, ?string $cost, ?string $rate, ?array $price, ?string $estimate, array $expected) {
    expect(ClickSpend::of($mode, $cost, $rate, TrafficCostUnit::Cpm, $price, $estimate))->toBe($expected);
})->with([
    'the link’s price' => [TrafficSpendMode::ClickCost, '0.0004', null, null, null, ['spend' => '0.00040000', 'basis' => TrafficSpendBasis::ClickCost, 'estimated' => false]],
    'no price in the link' => [TrafficSpendMode::ClickCost, null, null, null, null, ['spend' => '0.00000000', 'basis' => TrafficSpendBasis::None, 'estimated' => false]],
    'a rate per thousand' => [TrafficSpendMode::Rate, null, '1.5', null, null, ['spend' => '0.00150000', 'basis' => TrafficSpendBasis::Rate, 'estimated' => false]],
    'the campaign’s price' => [TrafficSpendMode::Price, null, null, ['price' => '0.02', 'model' => 'click'], null, ['spend' => '0.02000000', 'basis' => TrafficSpendBasis::CampaignPrice, 'estimated' => false]],
    'an estimate until the API' => [TrafficSpendMode::Api, '0.0004', '1.5', null, 'rate', ['spend' => '0.00150000', 'basis' => TrafficSpendBasis::Rate, 'estimated' => true]],
    'an estimate from the link' => [TrafficSpendMode::Manual, '0.0004', null, null, null, ['spend' => '0.00040000', 'basis' => TrafficSpendBasis::ClickCost, 'estimated' => true]],
    'a fixed price, shared later' => [TrafficSpendMode::Fixed, null, null, null, null, ['spend' => '0.00000000', 'basis' => TrafficSpendBasis::Fixed, 'estimated' => true]],
]);

it('spreads a fixed price over its days, the rounding on each block’s last day', function () {
    $month = FixedPriceSchedule::days('100', TrafficFixedPeriod::Month, '2026-02-01', null, '2026-03-02');

    expect(count($month))->toBe(30)
        ->and($month['2026-02-01'])->toBe('3.57142857')
        ->and(array_sum(array_map('floatval', array_slice($month, 0, 28))))->toEqualWithDelta(100.0, 1e-6)
        ->and($month['2026-03-01'])->toBe('3.22580645')
        ->and(FixedPriceSchedule::days('70', TrafficFixedPeriod::Week, '2026-11-01', '2026-11-03', '2026-11-30'))->toBe(['2026-11-01' => '10.00000000', '2026-11-02' => '10.00000000', '2026-11-03' => '10.00000000'])
        ->and(FixedPriceSchedule::days('50', TrafficFixedPeriod::Once, '2026-11-05', null, '2026-11-30'))->toBe(['2026-11-05' => '50.00000000'])
        ->and(FixedPriceSchedule::days('50', TrafficFixedPeriod::Day, '2026-12-05', null, '2026-11-30'))->toBe([]);
});

it('counts each month of a fixed price from its first day, so a short month moves no later one', function () {
    $days = FixedPriceSchedule::days('300', TrafficFixedPeriod::Month, '2027-01-31', '2027-03-30', '2027-04-30');

    // Jan 31 – Feb 27 is the first month, Feb 28 – Mar 30 the second: $600 to Mar 30, not $629.
    expect(array_sum(array_map('floatval', $days)))->toEqualWithDelta(600.0, 1e-6)
        ->and(count($days))->toBe(59)
        ->and(array_sum(array_map('floatval', array_slice($days, 0, 28))))->toEqualWithDelta(300.0, 1e-6);
});

it('shares figures from the narrowest first, so the visits add up to them exactly', function () {
    $plan = SpendAllocator::plan([
        ['id' => 1, 'campaign_id' => null, 'site_ref' => '', 'zone_ref' => '', 'amount' => '1.00'],
        ['id' => 2, 'campaign_id' => 7, 'site_ref' => 's1', 'zone_ref' => '', 'amount' => '0.10'],
        ['id' => 3, 'campaign_id' => 9, 'site_ref' => '', 'zone_ref' => '', 'amount' => '0.50'],
    ], [
        ['id' => 10, 'campaign_id' => 7, 'site_ref' => 's1', 'zone_ref' => 'z', 'cost' => '0.002'],
        ['id' => 11, 'campaign_id' => 7, 'site_ref' => 's1', 'zone_ref' => 'z', 'cost' => '0.001'],
        ['id' => 12, 'campaign_id' => 7, 'site_ref' => 's2', 'zone_ref' => 'z', 'cost' => null],
        ['id' => 13, 'campaign_id' => 8, 'site_ref' => 's3', 'zone_ref' => 'z', 'cost' => null],
        ['id' => 14, 'campaign_id' => 8, 'site_ref' => 's3', 'zone_ref' => 'z', 'cost' => null],
    ]);

    expect($plan['visits'][10])->toBe('0.06666666')
        ->and($plan['visits'][11])->toBe('0.03333334')
        ->and($plan['visits'][12])->toBe('0.33333333')
        ->and($plan['visits'][13])->toBe('0.33333333')
        ->and($plan['visits'][14])->toBe('0.33333334')
        ->and($plan['facts'][3])->toBe(['allocated' => '0.00000000', 'matched' => 0])
        ->and(array_sum(array_map('floatval', $plan['visits'])))->toEqualWithDelta(1.10, 1e-9);
});

it('shares a figure by price when a click carried a price of nothing, and equally when every one did', function () {
    $fact = [['id' => 1, 'campaign_id' => null, 'site_ref' => '', 'zone_ref' => '', 'amount' => '1.00']];
    $visit = fn (int $id, string $cost): array => ['id' => $id, 'campaign_id' => null, 'site_ref' => null, 'zone_ref' => null, 'cost' => $cost];

    $newestFree = SpendAllocator::plan($fact, [$visit(10, '1'), $visit(11, '2'), $visit(12, '0')])['visits'];

    expect(SpendAllocator::plan($fact, [$visit(10, '0'), $visit(11, '1')])['visits'])->toBe([10 => '0.00000000', 11 => '1.00000000'])
        ->and(SpendAllocator::plan($fact, [$visit(10, '0'), $visit(11, '0')])['visits'])->toBe([10 => '0.50000000', 11 => '0.50000000'])
        // What the rounding leaves goes to the newest click that cost something, never to one that cost nothing.
        ->and($newestFree)->toBe([10 => '0.33333333', 11 => '0.66666667', 12 => '0.00000000'])
        ->and((string) array_reduce($newestFree, fn (BigDecimal $sum, string $share): BigDecimal => $sum->plus($share), BigDecimal::zero()))->toBe('1.00000000');
});

it('reads a spend file, adding up repeated rows, or refuses the whole of it', function () {
    $file = SpendCsv::parse("\xEF\xBB\xBFDay;Amount;Campaign;Clicks\n2026-11-15;12.50;881204;900\n2026-11-15;0.50;881204;20\n2026-11-16;3;;\n", '2026-11-17');
    $broken = SpendCsv::parse("day,amount\n2026-11-15,12,5\n15.11.2026,3\n2026-11-30,1\n", '2026-11-17');

    expect($file['errors'])->toBe([])
        ->and($file['rows'])->toBe([
            ['day' => '2026-11-15', 'campaign' => '881204', 'site' => '', 'zone' => '', 'amount' => '13.00000000', 'clicks' => 920, 'impressions' => null],
            ['day' => '2026-11-16', 'campaign' => '', 'site' => '', 'zone' => '', 'amount' => '3.00000000', 'clicks' => null, 'impressions' => null],
        ])
        ->and($broken['rows'])->toBe([])
        ->and($broken['errors'])->toBe(['Row 2 has more cells than the header names. Write amounts with a point, such as 12.50, not a comma.', 'Row 3: “15.11.2026” isn’t a day written YYYY-MM-DD.', 'Row 4: 2026-11-30 hasn’t come yet.'])
        ->and(SpendCsv::parse("date,cost\n", '2026-11-17')['errors'][0])->toStartWith('The header has no “day” column.');
});

it('refuses a header that names a column twice, and keeps empty columns at the end', function () {
    $twice = SpendCsv::parse("day,amount,amount\n2026-11-15,100,1\n", '2026-11-17');
    $trailing = SpendCsv::parse("day;amount;;\n2026-11-15;1;;\n", '2026-11-17');

    expect($twice['rows'])->toBe([])
        ->and($twice['errors'])->toBe(['The header names “amount” more than once. Name each column once: day, amount, and if wanted campaign, site, zone, clicks, impressions.'])
        ->and($trailing['errors'])->toBe([])
        ->and($trailing['rows'])->toBe([['day' => '2026-11-15', 'campaign' => '', 'site' => '', 'zone' => '', 'amount' => '1.00000000', 'clicks' => null, 'impressions' => null]]);
});

it('adds up only rows of the same day, campaign, site and zone, as they are kept', function () {
    $apart = SpendCsv::parse("day,amount,campaign,site\n2026-11-15,1,a|b,c\n2026-11-15,2,a,b|c\n", '2026-11-17');
    $long = str_repeat('s', 120);
    $cut = SpendCsv::parse("day,amount,site\n2026-11-15,1,{$long}x\n2026-11-15,2,{$long}y\n", '2026-11-17');

    expect($apart['rows'])->toBe([
        ['day' => '2026-11-15', 'campaign' => 'a|b', 'site' => 'c', 'zone' => '', 'amount' => '1.00000000', 'clicks' => null, 'impressions' => null],
        ['day' => '2026-11-15', 'campaign' => 'a', 'site' => 'b|c', 'zone' => '', 'amount' => '2.00000000', 'clicks' => null, 'impressions' => null],
    ])
        ->and($cut['rows'])->toBe([['day' => '2026-11-15', 'campaign' => '', 'site' => $long, 'zone' => '', 'amount' => '3.00000000', 'clicks' => null, 'impressions' => null]]);
});
