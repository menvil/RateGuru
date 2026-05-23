<?php

use App\Support\Ranking\HotScoreCalculator;
use Carbon\CarbonImmutable;

it('has hot score calculator with calculate method', function () {
    $calculator = app(HotScoreCalculator::class);

    expect(method_exists($calculator, 'calculate'))->toBeTrue();
});

it('increases hot score with upvotes', function () {
    $calculator = app(HotScoreCalculator::class);

    $createdAt = CarbonImmutable::parse('2026-05-14 10:00:00');
    $now = CarbonImmutable::parse('2026-05-14 12:00:00');

    $lowScore = $calculator->calculate(
        upvotes: 1,
        downvotes: 0,
        commentsCount: 0,
        createdAt: $createdAt,
        now: $now,
    );

    $highScore = $calculator->calculate(
        upvotes: 10,
        downvotes: 0,
        commentsCount: 0,
        createdAt: $createdAt,
        now: $now,
    );

    expect($highScore)->toBeGreaterThan($lowScore);
});
