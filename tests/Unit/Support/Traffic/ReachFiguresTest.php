<?php

use App\Support\Traffic\Reports\ReachFigures;

it('works out what never arrived and what it cost, from one day of a pop network', function () {
    $day = new ReachFigures(impressions: 746, amount: '1.38', reached: 590, opened: 293, started: 7);

    expect($day->neverReached())->toBe(156)
        ->and(round($day->reachRate(), 4))->toBe(0.7909)
        ->and(round($day->neverReachedRate(), 4))->toBe(0.2091)
        ->and((string) $day->lostAmount())->toBe('0.28857909')
        ->and((new ReachFigures)->reachRate())->toBeNull()
        ->and((string) (new ReachFigures)->lostAmount())->toBe('0');
});

it('adds slices up raw, so visits outrunning one slice’s impressions take nothing off another’s, and holds only what is shown to sense', function () {
    $ahead = new ReachFigures(impressions: 10, amount: '0.10', reached: 12, opened: 6, started: 1);
    $behind = new ReachFigures(impressions: 10, amount: '0.10', reached: 5, opened: 2, started: 0);
    $sum = $ahead->plus($behind);

    expect($ahead->neverReached())->toBe(0)
        ->and($ahead->reachRate())->toBe(1.0)
        ->and((string) $ahead->lostAmount())->toBe('0.00000000')
        ->and([$sum->impressions, $sum->amount, $sum->reached, $sum->opened, $sum->started])->toBe([20, '0.20000000', 17, 8, 1])
        ->and($sum->neverReached())->toBe(3)
        ->and((string) $sum->lostAmount())->toBe('0.03000000');
});

it('falls short of its source only from so many impressions and below the share asked, never on the line', function () {
    // The source reached 80%: 70% of it is 56%.
    $source = new ReachFigures(impressions: 1000, amount: '2', reached: 800);

    expect((new ReachFigures(impressions: 500, reached: 279))->fallsShortOf($source, 500, 70))->toBeTrue()
        ->and((new ReachFigures(impressions: 500, reached: 280))->fallsShortOf($source, 500, 70))->toBeFalse()
        ->and((new ReachFigures(impressions: 499, reached: 100))->fallsShortOf($source, 500, 70))->toBeFalse()
        // Visits outrunning the impressions count as every one reached, on either side.
        ->and((new ReachFigures(impressions: 500, reached: 900))->fallsShortOf(new ReachFigures(impressions: 1000, reached: 1200), 500, 70))->toBeFalse()
        ->and((new ReachFigures(impressions: 500, reached: 340))->fallsShortOf(new ReachFigures(impressions: 1000, reached: 1200), 500, 70))->toBeTrue()
        ->and((new ReachFigures(impressions: 500, reached: 0))->fallsShortOf(new ReachFigures, 500, 70))->toBeFalse()
        ->and((new ReachFigures)->fallsShortOf($source, 0, 70))->toBeFalse();
});
