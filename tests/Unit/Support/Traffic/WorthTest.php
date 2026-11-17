<?php

use App\Enums\TrafficStepValueMode;
use App\Enums\TrafficValueBasis;
use App\Support\Traffic\Worth\ChanceCalculator;
use App\Support\Traffic\Worth\ChanceTable;
use App\Support\Traffic\Worth\ConfirmationCurve;
use App\Support\Traffic\Worth\Slice;
use App\Support\Traffic\Worth\StepWorth;
use App\Support\Traffic\Worth\VisitValue;
use App\Support\Traffic\Worth\WorthPrices;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

const WORTH_GROUPS = [
    ['key' => 't1', 'name' => 'Tier 1', 'percent' => 100, 'countries' => ['US', 'GB']],
    ['key' => 'rest', 'name' => 'Rest', 'percent' => 50, 'countries' => []],
];

/** One sample cell: visits of a game, source, device and country, with the steps they reached. */
function worthCell(array $cell): array
{
    return [...['game_id' => 1, 'source_id' => 3, 'channel' => 'network', 'device' => 'mobile', 'country' => 'US', 'rounds_answered' => 0, 'visits' => 0, 'accounts' => 0], ...$cell];
}

it('keys slices, leans each on the one above, and counts a visit in 16 of them', function () {
    $slice = new Slice(12, 3, null, 'mobile', 't1');

    expect($slice->key())->toBe('g12|s3|d:mobile|k:t1')
        ->and(array_map(fn (Slice $s) => $s->key(), $slice->chain()))->toBe(['g12|s3|d:mobile|k:t1', 'g12|s3|d:mobile', 'g12|s3', 'g12', '*'])
        ->and((new Slice(null, null, 'direct', null, 't1'))->parent()->key())->toBe('c:direct')
        ->and(count($slice->subsets()))->toBe(16)
        ->and(count((new Slice(null, 3, null, 'mobile', 't1'))->subsets()))->toBe(8);
});

it('works out the whole site from its own counts, and shrinks a slice towards the one above', function () {
    $rows = ChanceCalculator::calculate([
        worthCell(['visits' => 900, 'accounts' => 18, 'started_n' => 600, 'started_x' => 18]),
        worthCell(['source_id' => 4, 'visits' => 100, 'accounts' => 2, 'started_n' => 50, 'started_x' => 2]),
    ], WORTH_GROUPS);
    $find = fn (string $key, string $step) => collect($rows)->first(fn (array $row) => $row['slice']->key() === $key && $row['step'] === $step);

    $site = $find('*', 'opened');
    $game = $find('g1', 'opened');
    $source = $find('g1|s4', 'opened');

    expect($site['chance'])->toEqualWithDelta(20 / 1000, 1e-9)
        ->and($game['chance'])->toEqualWithDelta((20 + 30) / (1000 + 30 / 0.02), 1e-9)
        ->and($source['chance'])->toEqualWithDelta((2 + 30) / (100 + 30 / $game['chance']), 1e-9)
        ->and($source['thin'])->toBeTrue()
        ->and($source['stands_on'])->toBe('*')
        ->and($find('g1|s3', 'opened')['thin'])->toBeTrue()
        ->and($find('*', 'started')['chance'])->toEqualWithDelta(20 / 650, 1e-9);
});

it('counts a round for every visit that answered it or more', function () {
    $rows = ChanceCalculator::calculate([
        worthCell(['rounds_answered' => 4, 'visits' => 10, 'accounts' => 2]),
        worthCell(['rounds_answered' => 2, 'visits' => 30, 'accounts' => 1]),
    ], WORTH_GROUPS);
    $site = collect($rows)->filter(fn (array $row) => $row['slice']->key() === '*')->keyBy('step');

    expect($site['round_4']['visitors'])->toBe(10)
        ->and($site['round_3']['visitors'])->toBe(10)
        ->and($site['round_2']['visitors'])->toBe(40)
        ->and($site['round_1']['visitors'])->toBe(40)
        ->and($site['round_2']['accounts'])->toBe(3);
});

it('shrinks how often accounts post towards the slice above', function () {
    $rows = ChanceCalculator::calculate([
        worthCell(['visits' => 100, 'accounts' => 40, 'post_n' => 40, 'post_x' => 20]),
        worthCell(['game_id' => 2, 'visits' => 100, 'accounts' => 10, 'post_n' => 10, 'post_x' => 0]),
    ], WORTH_GROUPS);
    $find = fn (string $key) => collect($rows)->first(fn (array $row) => $row['slice']->key() === $key && $row['step'] === 'post_bonus');

    expect($find('*')['chance'])->toEqualWithDelta(20 / 50, 1e-9)
        ->and($find('g2')['chance'])->toEqualWithDelta((0 + 30 * 0.4) / (10 + 30), 1e-9);
});

it('reads a chance from the slice or the nearest one above, thin when it is not its own', function () {
    $table = new ChanceTable(['g1' => ['started' => ['chance' => '0.03', 'thin' => false]], '*' => ['started' => ['chance' => '0.02', 'thin' => false]]]);

    expect($table->of(new Slice(1, 3, null, 'mobile', 't1'), 'started'))->toBe(['chance' => 0.03, 'thin' => true])
        ->and($table->of(new Slice(1), 'started'))->toBe(['chance' => 0.03, 'thin' => false])
        ->and($table->of(new Slice(2), 'opened'))->toBe(['chance' => 0.0, 'thin' => true]);
});

it('works out what a step is worth, in order: known, sign-in, account, a value by hand, the chance', function () {
    $prices = new WorthPrices('0.5', '1', '0.2');
    $table = new ChanceTable(['*' => ['started' => ['chance' => '0.1', 'thin' => false], 'post_bonus' => ['chance' => '0.5', 'thin' => false], 'returned_bonus' => ['chance' => '0.5', 'thin' => false]]]);
    $site = Slice::site();
    $account = BigDecimal::of('1.1');

    expect(StepWorth::of($site, 'started', $prices, 100, true, null, $table)['basis'])->toBe(TrafficValueBasis::None)
        ->and(StepWorth::of($site, 'login', $prices, 100, false, null, $table)['basis'])->toBe(TrafficValueBasis::None)
        ->and((string) StepWorth::of($site, 'account', $prices, 50, false, null, $table)['value'])->toBe('0.55000000')
        ->and((string) StepWorth::of($site, 'started', $prices, 100, false, null, $table)['value'])->toBe((string) $account->multipliedBy('0.1')->toScale(8))
        ->and(StepWorth::of($site, 'started', $prices, 100, false, null, $table)['basis'])->toBe(TrafficValueBasis::Measured)
        ->and((string) StepWorth::of($site, 'started', $prices, 100, false, ['value' => '0.07', 'mode' => TrafficStepValueMode::Always], $table)['value'])->toBe('0.07000000')
        ->and(StepWorth::of($site, 'started', $prices, 100, false, ['value' => '0.07', 'mode' => TrafficStepValueMode::Thin], $table)['basis'])->toBe(TrafficValueBasis::Measured)
        ->and(StepWorth::of(new Slice(9), 'started', $prices, 100, false, ['value' => '0.07', 'mode' => TrafficStepValueMode::Thin], $table)['basis'])->toBe(TrafficValueBasis::Manual);
});

it('works out what a visit brought and may still bring', function () {
    $prices = new WorthPrices('0.5', '1', '0.2');
    $now = CarbonImmutable::parse('2026-11-17 12:00:00');
    $arrived = $now->subDay();
    $holds = $arrived->addDays(7);
    $reached = [['value' => BigDecimal::of('0.05'), 'basis' => TrafficValueBasis::Measured, 'chance' => 0.1]];
    $value = fn (...$changes) => VisitValue::of(...[
        'known' => false, 'arrivedAt' => $arrived, 'holdsUntil' => $holds, 'accountAt' => null, 'signupAt' => null, 'posted' => false, 'returned' => false,
        'reached' => $reached, 'prices' => $prices, 'percent' => 100, 'postRate' => 0.0, 'returnRate' => 0.0, 'curve' => ConfirmationCurve::none(), 'now' => $now, ...$changes,
    ]);

    expect($value())->toBe(['real' => '0.00000000', 'expected' => '0.05000000'])
        ->and($value(known: true))->toBe(['real' => '0.00000000', 'expected' => '0.00000000'])
        ->and($value(now: $holds->addDays(7)))->toBe(['real' => '0.00000000', 'expected' => '0.00000000'])
        ->and($value(accountAt: $now, signupAt: $now, posted: true, percent: 50, returnRate: 0.5))->toBe(['real' => '0.75000000', 'expected' => '0.05000000']);
});

it('reads how soon accounts are confirmed, trusting it only with 30 accounts or more', function () {
    $curve = new ConfirmationCurve([20, 40, 60, 80, 90, 95, 100, 100, 100], 100);

    expect($curve->share(600))->toEqualWithDelta(0.2, 1e-9)
        ->and($curve->share(300))->toEqualWithDelta(0.1, 1e-9)
        ->and($curve->spent(3600, 604800))->toEqualWithDelta(0.4, 1e-9)
        ->and((new ConfirmationCurve([1, 2, 3, 4, 5, 6, 7, 8, 9], 9))->spent(3600, 604800))->toBe(0.0);
});
