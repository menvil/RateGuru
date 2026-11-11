<?php

use App\Support\Games\Traffic\TrafficShareValidator;
use App\Support\Games\Traffic\VersionAssigner;

it('applies whole shares of active versions that add up to 100', function () {
    expect(TrafficShareValidator::issues([1 => 80, 2 => 20], [1, 2]))->toBe([])
        ->and(TrafficShareValidator::issues([], [1, 2]))->toBe([])
        ->and(TrafficShareValidator::issues([1 => 80, 2 => 15], [1, 2]))->toBe(['Shares add up to 95%. Make them 100% to apply.'])
        ->and(TrafficShareValidator::issues([1 => 0, 2 => 100], [1, 2]))->toBe(['Each share is a whole number from 1 to 100.'])
        // Numbers in name only, though they add up to 100.
        ->and(TrafficShareValidator::issues([1 => '50', 2 => '50'], [1, 2]))->toBe(['Each share is a whole number from 1 to 100.'])
        ->and(TrafficShareValidator::issues([1 => 50.5, 2 => 49.5], [1, 2]))->toBe(['Each share is a whole number from 1 to 100.'])
        ->and(TrafficShareValidator::issues([1 => 50, 3 => 50], [1, 2]))->toBe(['Only active versions of this game can have a share.']);
});

it('gives a visitor the same version every time', function () {
    $shares = [11 => 80, 12 => 20];

    expect(VersionAssigner::choose($shares, 'game:1|period:3|v:abc'))
        ->toBe(VersionAssigner::choose($shares, 'game:1|period:3|v:abc'));
});

it('shares visitors out as the shares say', function () {
    $counts = [11 => 0, 12 => 0];

    for ($visitor = 0; $visitor < 10_000; $visitor++) {
        $counts[VersionAssigner::choose([11 => 80, 12 => 20], "game:1|period:1|v:{$visitor}")]++;
    }

    expect($counts[11])->toBeGreaterThan(7_700)->toBeLessThan(8_300)
        ->and($counts[12])->toBeGreaterThan(1_700)->toBeLessThan(2_300);
});

it('divides an unplayable version\'s share between the rest', function () {
    $counts = [11 => 0, 13 => 0];

    for ($visitor = 0; $visitor < 6_000; $visitor++) {
        $counts[VersionAssigner::choose([11 => 50, 13 => 25], "v:{$visitor}")]++;
    }

    // 50 : 25 of what is left, so two thirds and one third.
    expect($counts[11])->toBeGreaterThan(3_700)->toBeLessThan(4_300);
});

it('assigns nothing when no version has a share', function () {
    expect(VersionAssigner::choose([], 'v:1'))->toBeNull();
});
