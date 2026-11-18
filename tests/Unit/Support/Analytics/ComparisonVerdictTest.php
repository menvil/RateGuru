<?php

use App\Enums\GameAudience;
use App\Support\Analytics\ComparedVersion;
use App\Support\Analytics\ComparisonVerdict;
use App\Support\Analytics\PeriodComparison;
use App\Support\Analytics\PeriodVersionFunnel;

/*
 * What a period's comparison says so far, on its primary metric: a version
 * won once the evidence on it is strong — the best of those ahead of the
 * reference, or the reference itself with every other version behind it —
 * and short of that, paused, no winner yet with how far apart the versions
 * are and what is still missing, or no clear difference once over.
 */

/**
 * A period's versions with their shares and, by version number, how many
 * were assigned to each and how many made an account — every account old
 * enough to have activated, unless the matured accounts are given.
 *
 * @param  array<int, int>  $shares
 * @param  array<int, array{0: int, 1: int, 2?: int}>  $figures  assigned, accounts, matured
 */
function verdictComparison(array $shares, array $figures, GameAudience $audience = GameAudience::Anonymous): PeriodComparison
{
    $versions = [];
    $funnels = [];

    foreach ($shares as $number => $share) {
        $versions[] = new ComparedVersion($number, $number, $share);
        [$assigned, $accounts] = $figures[$number] ?? [0, 0];
        $funnels[$number] = new PeriodVersionFunnel(assigned: $assigned, accounts: $accounts, goal: $accounts, matured: $figures[$number][2] ?? $accounts);
    }

    return new PeriodComparison($audience, $versions, $funnels);
}

it('names the reference the winner once every other version is behind it with strong evidence', function () {
    $verdict = ComparisonVerdict::of(verdictComparison([7 => 50, 8 => 50], [7 => [30, 25], 8 => [30, 10]]), ended: true, paused: false, when: '22 Sep');

    expect($verdict->kind)->toBe(ComparisonVerdict::WON)
        ->and($verdict->winner?->label())->toBe('V7')
        ->and($verdict->title)->toBe('V7 won on accounts.')
        ->and($verdict->text)->toBe('V8 −60.0% against V7, with strong evidence.');
});

it('names a version ahead of the reference with strong evidence the winner, the highest rate among several', function () {
    $one = ComparisonVerdict::of(verdictComparison([7 => 80, 8 => 20], [7 => [30, 10], 8 => [30, 25]]), ended: false, paused: false, when: 'Day 8');

    expect($one->kind)->toBe(ComparisonVerdict::WON)
        ->and($one->title)->toBe('V8 won on accounts.')
        ->and($one->text)->toBe('V8 +150.0% against V7, with strong evidence.');

    $several = ComparisonVerdict::of(verdictComparison([7 => 50, 8 => 25, 9 => 25], [7 => [30, 10], 8 => [30, 22], 9 => [30, 25]]), ended: false, paused: false, when: 'Day 8');

    expect($several->winner?->label())->toBe('V9')
        ->and($several->text)->toBe('V9 +150.0%, V8 +120.0% against V7, each with strong evidence.');

    // Logged-in players are compared by the goal.
    expect(ComparisonVerdict::of(verdictComparison([1 => 50, 2 => 50], [1 => [30, 10], 2 => [30, 25]], GameAudience::Authenticated), ended: true, paused: false, when: '22 Sep')->title)
        ->toBe('V2 won on players who reached the goal.');
});

it('names no winner while some other version isn’t behind the reference with strong evidence', function () {
    $verdict = ComparisonVerdict::of(verdictComparison([7 => 50, 8 => 25, 9 => 25], [7 => [30, 25], 8 => [30, 10], 9 => [30, 22]]), ended: false, paused: false, when: 'Day 8');

    expect($verdict->kind)->toBe(ComparisonVerdict::RUNNING)
        ->and($verdict->winner)->toBeNull()
        ->and($verdict->title)->toBe('No winner yet.')
        ->and($verdict->text)->toBe('Day 8. Accounts so far: V8 −60.0%, V9 −12.0% against V7; no version leads with strong evidence.');
});

it('says a period of a paused game is paused, unless a version won already', function () {
    $paused = ComparisonVerdict::of(verdictComparison([7 => 80, 8 => 20], [7 => [40, 3], 8 => [10, 1]]), ended: false, paused: true, when: 'Day 8');

    expect($paused->kind)->toBe(ComparisonVerdict::PAUSED)
        ->and($paused->title)->toBe('Paused.')
        ->and($paused->text)->toBe('New visitors aren’t being assigned while the game is paused. Results so far are below.')
        ->and(ComparisonVerdict::of(verdictComparison([7 => 80, 8 => 20], [7 => [30, 10], 8 => [30, 25]]), ended: false, paused: true, when: 'Day 8')->kind)->toBe(ComparisonVerdict::WON)
        // An ended period is over whatever its game does now.
        ->and(ComparisonVerdict::of(verdictComparison([7 => 80, 8 => 20], [7 => [40, 3], 8 => [10, 1]]), ended: true, paused: true, when: '22 Sep')->kind)->toBe(ComparisonVerdict::ENDED);
});

it('says what a period still open lacks: visitors, the primary metric, or accounts old enough to activate', function () {
    expect(ComparisonVerdict::of(verdictComparison([7 => 50, 8 => 50], []), ended: false, paused: false, when: 'Day 1')->text)
        ->toBe('Day 1. Numbers appear as visitors arrive.')
        ->and(ComparisonVerdict::of(verdictComparison([7 => 50, 8 => 50], [7 => [40, 12], 8 => [40, 4]]), ended: false, paused: false, when: 'Day 3')->text)
        ->toBe('Day 3. Too early: V8 has 4 of 10 accounts.')
        ->and(ComparisonVerdict::of(verdictComparison([7 => 50, 8 => 50], [7 => [40, 2, 0], 8 => [40, 1, 0]]), ended: false, paused: false, when: 'Day 3')->text)
        ->toBe('Day 3. Too early: V7 has 2 of 10 accounts, V8 has 1 of 10 accounts. Activation waits for accounts to have their 7 days.');
});

it('says an ended period showed no clear difference, or too little to tell', function () {
    $even = ComparisonVerdict::of(verdictComparison([7 => 50, 8 => 50], [7 => [100, 20], 8 => [100, 21]]), ended: true, paused: false, when: '22 Sep');

    expect($even->kind)->toBe(ComparisonVerdict::ENDED)
        ->and($even->title)->toBe('No clear difference on accounts.')
        ->and($even->text)->toBe('The period ended 22 Sep.')
        ->and(ComparisonVerdict::of(verdictComparison([7 => 50, 8 => 50], [7 => [100, 20], 8 => [100, 3]]), ended: true, paused: false, when: '22 Sep')->text)
        ->toBe('The period ended 22 Sep. Too early: V8 has 3 of 10 accounts.')
        ->and(ComparisonVerdict::of(verdictComparison([7 => 50, 8 => 50], []), ended: true, paused: false, when: '22 Sep')->title.' '.ComparisonVerdict::of(verdictComparison([7 => 50, 8 => 50], []), ended: true, paused: false, when: '22 Sep')->text)
        ->toBe('No winner. The period ended 22 Sep. Nobody was sent to a version in it.');
});

it('names no winner of an ended period where one version is clearly behind and none clearly ahead', function () {
    $verdict = ComparisonVerdict::of(verdictComparison([7 => 50, 8 => 25, 9 => 25], [7 => [100, 40], 8 => [100, 15], 9 => [100, 38]]), ended: true, paused: false, when: '22 Oct');

    expect($verdict->kind)->toBe(ComparisonVerdict::ENDED)
        ->and($verdict->title)->toBe('No winner on accounts.')
        ->and($verdict->text)->toStartWith('The period ended 22 Oct. Accounts: ')
        ->and($verdict->text)->toEndWith('; no version leads with strong evidence.');
});

it('writes a relative difference with its sign, to a tenth of a per cent', function () {
    expect(ComparisonVerdict::change(12.0))->toBe('+12.0%')
        ->and(ComparisonVerdict::change(-4.349))->toBe('−4.3%')
        ->and(ComparisonVerdict::change(-0.04))->toBe('+0.0%')
        ->and(ComparisonVerdict::change(null))->toBe('—');
});
