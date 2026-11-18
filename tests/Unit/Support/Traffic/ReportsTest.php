<?php

use App\Enums\TrafficBlockVerdict;
use App\Support\Traffic\Blocking\BlockJudge;
use App\Support\Traffic\Reports\ReportFigures;
use App\Support\Traffic\Reports\ReportTree;
use App\Support\Traffic\Reports\TwoProportionTest;

const BLOCK_RULE = ['block_min_spend' => '2', 'block_min_clicks' => 200, 'block_roi_below' => -40, 'block_open_below' => 50, 'block_bot_clicks' => 30, 'block_start_clicks' => 60, 'block_start_percent' => 60];

it('judges a site by its last 30 days, at the edges of each rule', function (array $figures, ?float $startRate, bool $worthKnown, ?TrafficBlockVerdict $verdict, string $state) {
    expect(BlockJudge::judge(new ReportFigures(...$figures), $startRate, $worthKnown, BLOCK_RULE))->toBe(['verdict' => $verdict, 'state' => $state]);
})->with([
    'bots: 30 clicks, 14 opened' => [['visits' => 30, 'opened' => 14], null, false, TrafficBlockVerdict::Bots, 'bots'],
    'not bots: 15 of 30 opened' => [['visits' => 30, 'opened' => 15], null, false, null, 'early'],
    'not bots yet: 29 clicks' => [['visits' => 29, 'opened' => 0], null, false, null, 'early'],
    'losing: −50 %' => [['visits' => 100, 'opened' => 90, 'spend' => '2', 'valueReal' => '1'], 0.5, true, TrafficBlockVerdict::Losing, 'losing'],
    'not judged before accounts are measured' => [['visits' => 100, 'opened' => 90, 'spend' => '2', 'valueReal' => '0'], 0.5, false, null, 'early'],
    'watch: −20 %' => [['visits' => 100, 'opened' => 90, 'spend' => '2', 'valueReal' => '1.6'], 0.5, true, null, 'watch'],
    'profit' => [['visits' => 200, 'opened' => 190, 'spend' => '1', 'valueReal' => '1.2'], 0.5, true, null, 'profit'],
    'weak start' => [['visits' => 60, 'opened' => 50, 'started' => 14, 'spend' => '0.5'], 0.5, false, TrafficBlockVerdict::WeakStart, 'weak_start'],
    'start fine' => [['visits' => 60, 'opened' => 50, 'started' => 15, 'spend' => '0.5'], 0.5, false, null, 'early'],
]);

it('tells whether one version brings more accounts than another', function () {
    expect(TwoProportionTest::compare(1000, 9, 1000, 30)['verdict'])->toBe('too_early')
        ->and(TwoProportionTest::compare(2000, 60, 2000, 30)['verdict'])->toBe('leader')
        ->and(TwoProportionTest::compare(2000, 40, 2000, 30)['verdict'])->toBe('no_difference')
        ->and(TwoProportionTest::compare(2000, 44, 2000, 30))->toMatchArray(['verdict' => 'ahead'])
        ->and(TwoProportionTest::compare(2000, 44, 2000, 30)['needed'])->toBe(5807)
        ->and(TwoProportionTest::erf(1.0))->toEqualWithDelta(0.8427007929, 1e-6);
});

it('has enough to compare versions once every one has its ten accounts, and not a version fewer', function () {
    expect(TwoProportionTest::enoughToCompare(10, 10))->toBeTrue()
        ->and(TwoProportionTest::enoughToCompare(40, 10, 12))->toBeTrue()
        ->and(TwoProportionTest::enoughToCompare(40, 9))->toBeFalse()
        ->and(TwoProportionTest::enoughToCompare(40, 30, 9))->toBeFalse()
        ->and(TwoProportionTest::enoughToCompare())->toBeFalse()
        // The comparison itself draws the line in the same place.
        ->and(TwoProportionTest::compare(1000, 10, 1000, 10)['verdict'])->not->toBe('too_early')
        ->and(TwoProportionTest::compare(1000, 9, 1000, 30)['verdict'])->toBe('too_early');
});

it('draws a level as its ten biggest groups and the rest in one row that adds up', function () {
    $groups = array_map(fn (int $i) => ['key' => $i, 'figures' => new ReportFigures(visits: 20 - $i, accounts: 1, spend: '0.5')], range(0, 13));
    $level = ReportTree::level($groups);

    expect(count($level['shown']))->toBe(10)
        ->and($level['restCount'])->toBe(4)
        ->and($level['rest']->visits)->toBe(7 + 8 + 9 + 10)
        ->and($level['total']->visits)->toBe(array_sum(range(7, 20)))
        ->and($level['total']->spend)->toBe('7.00000000');
});

it('reads a row’s rates and its worth against its spend', function () {
    $row = new ReportFigures(visits: 100, opened: 80, started: 40, accounts: 4, knownOpened: 10, spend: '2', valueReal: '2.5', valueExpected: '0.5');

    expect($row->newVisitors())->toBe(70)
        ->and($row->roi())->toBe(50.0)
        ->and($row->costPerAccount())->toBe('0.5000')
        ->and($row->startRate())->toBe(0.5)
        ->and($row->accountRate())->toEqualWithDelta(4 / 70, 1e-12)
        ->and((new ReportFigures)->roi())->toBeNull();
});
