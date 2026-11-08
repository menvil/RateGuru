<?php

use App\Support\Games\Results\ResultProfileCoverage;
use App\Support\Games\Results\ResultProfileRange;

function profileRange(string $name, int $min, int $max): ResultProfileRange
{
    return new ResultProfileRange($name, $min, $max);
}

it('accepts profiles that cover every count of matches exactly once', function () {
    $profiles = [profileRange('Wild Card', 0, 1), profileRange('Independent', 2, 3), profileRange('Sharp Eye', 4, 4), profileRange('Oracle', 5, 5)];

    expect(ResultProfileCoverage::issues($profiles, 5))->toBe([]);
});

it('names overlaps, gaps and ranges a player cannot reach', function () {
    $profiles = [profileRange('Wild Card', 0, 2), profileRange('Independent', 2, 3), profileRange('Oracle', 6, 6)];

    expect(ResultProfileCoverage::issues($profiles, 5))->toBe([
        'Oracle goes up to 6 matches, but the most a player can match is 5.',
        'Wild Card and Independent overlap at 2 matches.',
        'No profile covers 4–5 matches.',
    ]);
});

it('says a range that starts above its end is broken', function () {
    expect(ResultProfileCoverage::issues([profileRange('Odd', 3, 1), profileRange('All', 0, 3)], 3))
        ->toBe(['Odd: the range starts above its end.']);
});

it('asks for profiles when there are none', function () {
    expect(ResultProfileCoverage::issues([], 5))->toBe(['No result profiles yet.']);
});

it('finds the profile a number of matches falls in', function () {
    $profiles = [profileRange('Low', 0, 1), profileRange('High', 2, 3)];

    expect(ResultProfileCoverage::profileFor($profiles, 0))->toBe(0)
        ->and(ResultProfileCoverage::profileFor($profiles, 3))->toBe(1)
        ->and(ResultProfileCoverage::profileFor($profiles, 4))->toBeNull();
});

it('suggests ranges that never overlap and always cover every count', function (int $total) {
    $ranges = ResultProfileCoverage::suggestedRanges($total);
    $names = ResultProfileCoverage::suggestedNames($total);
    $profiles = array_map(fn (array $range, int $name): ResultProfileRange => profileRange(ResultProfileCoverage::SUGGESTED[$name]['name'], $range['min'], $range['max']), $ranges, $names);

    expect(ResultProfileCoverage::issues($profiles, $total))->toBe([])
        ->and(end($profiles)->name)->toBe('Oracle')
        ->and($profiles[0]->name)->toBe('Wild Card');
})->with([1, 2, 3, 4, 5, 6, 7, 10, 20]);

it('suggests the prototype ranges for five rounds', function () {
    expect(ResultProfileCoverage::suggestedRanges(5))->toBe([
        ['min' => 0, 'max' => 1],
        ['min' => 2, 'max' => 3],
        ['min' => 4, 'max' => 4],
        ['min' => 5, 'max' => 5],
    ]);
});
