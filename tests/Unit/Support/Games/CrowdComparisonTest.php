<?php

use App\Enums\GameCrowdState;
use App\Support\Games\Versus\CrowdComparison;
use App\Support\Games\Versus\CrowdSide;

function crowdSide(int $postId, int $up, int $down, bool $eligible = true): CrowdSide
{
    return new CrowdSide($postId, $up, $down, $eligible);
}

it('prefers the post with the larger share of upvotes, not the one with more votes', function () {
    // 92 % of 100 against 80 % of 1 000: the larger share wins.
    $comparison = CrowdComparison::of(crowdSide(1, 92, 8), crowdSide(2, 800, 200), minVotes: 100, minGap: 5);

    expect($comparison->state)->toBe(GameCrowdState::Majority)
        ->and($comparison->preferred)->toBe(0);
});

it('compares the exact shares, never the rounded percentages', function () {
    // 2/3 = 66.666… % against 667/1000 = 66.7 %: both shown as 66.7 when
    // rounded to one decimal, yet B's share is the larger — and with no gap
    // required, B is the pick.
    $comparison = CrowdComparison::of(crowdSide(1, 200, 100), crowdSide(2, 667, 333), minVotes: 100, minGap: 0);

    expect($comparison->state)->toBe(GameCrowdState::Majority)
        ->and($comparison->preferred)->toBe(1)
        ->and($comparison->a->displayShare())->toBe(66.7)
        ->and($comparison->b->displayShare())->toBe(66.7);
});

it('calls exactly equal shares a tie', function () {
    $comparison = CrowdComparison::of(crowdSide(1, 83, 17), crowdSide(2, 166, 34), minVotes: 100, minGap: 0);

    expect($comparison->state)->toBe(GameCrowdState::Tie)
        ->and($comparison->preferred)->toBeNull();
});

it('calls shares closer than the gap too close to call', function () {
    // 83 % against 81 %: 2 points, under the 5 required.
    $comparison = CrowdComparison::of(crowdSide(1, 83, 17), crowdSide(2, 81, 19), minVotes: 100, minGap: 5);

    expect($comparison->state)->toBe(GameCrowdState::Ambiguous);
});

it('accepts a gap of exactly the required points', function () {
    // 85 % against 80 %: exactly 5 points.
    $comparison = CrowdComparison::of(crowdSide(1, 80, 20), crowdSide(2, 85, 15), minVotes: 100, minGap: 5);

    expect($comparison->state)->toBe(GameCrowdState::Majority)
        ->and($comparison->preferred)->toBe(1);
});

it('needs the required votes on each post', function () {
    $comparison = CrowdComparison::of(crowdSide(1, 99, 0), crowdSide(2, 10, 200), minVotes: 100, minGap: 5);

    expect($comparison->state)->toBe(GameCrowdState::Insufficient)
        ->and($comparison->shortSide())->toBe(0);
});

it('never scores a post with no votes, even with no threshold', function () {
    $comparison = CrowdComparison::of(crowdSide(1, 0, 0), crowdSide(2, 10, 0), minVotes: 0, minGap: 0);

    expect($comparison->state)->toBe(GameCrowdState::Insufficient);
});

it('refuses a pair missing a post or showing one post twice', function (?int $a, ?int $b) {
    $comparison = CrowdComparison::of(new CrowdSide($a, 90, 10), new CrowdSide($b, 50, 50), minVotes: 1, minGap: 0);

    expect($comparison->state)->toBe(GameCrowdState::Missing);
})->with([
    'no first post' => [null, 2],
    'no second post' => [1, null],
    'the same post twice' => [7, 7],
]);

it('refuses a post that can no longer be shown', function () {
    $comparison = CrowdComparison::of(crowdSide(1, 900, 100), crowdSide(2, 100, 900, eligible: false), minVotes: 100, minGap: 5);

    expect($comparison->state)->toBe(GameCrowdState::Ineligible);
});
