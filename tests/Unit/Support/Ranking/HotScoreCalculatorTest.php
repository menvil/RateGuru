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

it('does not allow downvotes to make hot score negative', function () {
    $calculator = app(HotScoreCalculator::class);

    $score = $calculator->calculate(
        upvotes: 0,
        downvotes: 10,
        commentsCount: 0,
        createdAt: CarbonImmutable::parse('2026-05-14 10:00:00'),
        now: CarbonImmutable::parse('2026-05-14 12:00:00'),
    );

    expect($score)->toBeGreaterThanOrEqual(0);
});

it('decreases hot score with age', function () {
    $calculator = app(HotScoreCalculator::class);

    $now = CarbonImmutable::parse('2026-05-14 12:00:00');

    $newScore = $calculator->calculate(
        upvotes: 10,
        downvotes: 0,
        commentsCount: 0,
        createdAt: CarbonImmutable::parse('2026-05-14 11:00:00'),
        now: $now,
    );

    $oldScore = $calculator->calculate(
        upvotes: 10,
        downvotes: 0,
        commentsCount: 0,
        createdAt: CarbonImmutable::parse('2026-05-13 12:00:00'),
        now: $now,
    );

    expect($oldScore)->toBeLessThan($newScore);
});
