<?php

use App\Support\Traffic\Reports\ReportFigures;
use App\Support\Traffic\Reports\ReportTree;
use App\Support\Traffic\Reports\TwoProportionTest;

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
