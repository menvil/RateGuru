<?php

use App\Support\Analytics\SampleRatioCheck;

/*
 * Whether a period's visitors were shared out the way its shares say: a
 * chi-square test of the visitors each version got against its share, off
 * below a chance of one in a thousand, untested with nobody assigned or
 * while a version would expect fewer than five.
 */

it('reads a split close to the shares as balanced', function () {
    $check = new SampleRatioCheck([510, 490], [50, 50]);

    expect($check->total())->toBe(1000)
        ->and($check->statistic())->toEqualWithDelta(0.4, 1e-9)
        ->and($check->pValue())->toEqualWithDelta(0.5271, 1e-4)
        ->and($check->looksOff())->toBeFalse()
        ->and($check->label())->toBe('Balanced')
        ->and($check->tone())->toBe('neutral');
});

it('says a split far from the shares looks off', function (array $assigned, array $shares, float $statistic, float $p, float $delta) {
    $check = new SampleRatioCheck($assigned, $shares);

    expect($check->statistic())->toEqualWithDelta($statistic, 1e-9)
        ->and($check->pValue())->toEqualWithDelta($p, $delta)
        ->and($check->looksOff())->toBeTrue()
        ->and($check->label())->toBe('Split looks off')
        ->and($check->tone())->toBe('warning');
})->with([
    'two versions, 60/40 for 50/50' => [[600, 400], [50, 50], 40.0, 0.0, 1e-6],
    'half and half for 80/20' => [[30, 30], [80, 20], 33.75, 0.0, 1e-6],
    'four versions, by three degrees of freedom' => [[130, 100, 100, 70], [25, 25, 25, 25], 18.0, 4.40e-4, 1e-6],
]);

it('works out the chance for every number of versions', function (array $assigned, array $shares, float $statistic, float $p) {
    $check = new SampleRatioCheck($assigned, $shares);

    expect($check->statistic())->toEqualWithDelta($statistic, 1e-3)
        ->and($check->pValue())->toEqualWithDelta($p, 1e-4)
        ->and($check->looksOff())->toBeFalse();
})->with([
    'three versions against unequal shares' => [[100, 100, 100], [40, 30, 30], 5.556, 0.0622],
    'exactly as shared' => [[25, 25, 25, 25], [25, 25, 25, 25], 0.0, 1.0],
]);

it('tests nothing with nobody assigned or while a version would expect fewer than five', function () {
    expect((new SampleRatioCheck([0, 0], [50, 50]))->label())->toBe('No visitors yet')
        ->and((new SampleRatioCheck([0, 0], [50, 50]))->pValue())->toBeNull()
        ->and((new SampleRatioCheck([3, 1], [50, 50]))->label())->toBe('Too early')
        ->and((new SampleRatioCheck([3, 1], [50, 50]))->looksOff())->toBeFalse()
        // 40 visitors, 4 expected where the share is 10%.
        ->and((new SampleRatioCheck([40, 0], [90, 10]))->label())->toBe('Too early')
        ->and((new SampleRatioCheck([200], [100]))->pValue())->toBeNull();
});

it('needs a share for every version', function () {
    new SampleRatioCheck([10, 10], [100]);
})->throws(InvalidArgumentException::class);
