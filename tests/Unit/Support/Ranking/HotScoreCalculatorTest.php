<?php

use App\Support\Ranking\HotScoreCalculator;

it('has hot score calculator with calculate method', function () {
    $calculator = app(HotScoreCalculator::class);

    expect(method_exists($calculator, 'calculate'))->toBeTrue();
});
