<?php

use App\Support\Analytics\VisitorFigures;

/*
 * The people of an Analytics figure, read the same whatever the engine
 * returned: the start rate a share of every visitor, completion of those who
 * started, the account and activation rates of the new visitors only — and
 * a rate none with nothing to divide by, or below the denominator a table
 * row needs before it shows one.
 */

it('reads a row as any engine returns it', function () {
    $figures = VisitorFigures::fromRow((object) [
        'visitors' => '120', 'new_visitors' => 100, 'starters' => '60', 'completers' => '45', 'goal' => '20', 'signup_form' => '30',
        'signups' => '12', 'accounts' => '10', 'activated' => '4', 'matured_accounts' => '8', 'votes' => '31', 'voters' => '9',
        'comments' => '5', 'commenters' => '3', 'posts' => '2', 'spend' => '12.5', 'spend_estimated' => null,
    ]);

    expect([$figures->visitors, $figures->newVisitors, $figures->starters, $figures->completers, $figures->goal, $figures->signupForm])->toBe([120, 100, 60, 45, 20, 30])
        ->and([$figures->signups, $figures->accounts, $figures->activated, $figures->maturedAccounts])->toBe([12, 10, 4, 8])
        ->and([$figures->votes, $figures->voters, $figures->comments, $figures->commenters, $figures->posts, $figures->returning])->toBe([31, 9, 5, 3, 2, 0])
        ->and([$figures->spend, $figures->spendEstimated])->toBe(['12.50000000', '0.00000000'])
        ->and($figures->isEstimated())->toBeFalse()
        ->and($figures->withReturning(7)->returning)->toBe(7)
        ->and($figures->withReturning(7)->accounts)->toBe(10)
        ->and(VisitorFigures::fromRow([])->isEmpty())->toBeTrue()
        ->and(VisitorFigures::fromRow(['spend_estimated' => '0.25'])->isEstimated())->toBeTrue();
});

it('divides starts by visitors, completions by starters, accounts and activations by new visitors', function () {
    $figures = new VisitorFigures(visitors: 200, newVisitors: 50, starters: 100, completers: 75, accounts: 5, activated: 2);

    expect($figures->startRate())->toBe(0.5)
        ->and($figures->completion())->toBe(0.75)
        ->and($figures->accountRate())->toBe(0.1)
        ->and($figures->activationRate())->toBe(0.04);
});

it('has no rate with nothing to divide by', function () {
    $figures = new VisitorFigures(visitors: 3, newVisitors: 0, accounts: 0);

    expect($figures->accountRate())->toBeNull()
        ->and($figures->activationRate())->toBeNull()
        ->and($figures->completion())->toBeNull()
        ->and($figures->startRate())->toBe(0.0)
        ->and(VisitorFigures::rate(1, 0))->toBeNull()
        ->and(VisitorFigures::rate(1, 0, 0))->toBeNull();
});

it('shows a row’s rate only from the denominator a row needs', function () {
    $thin = new VisitorFigures(visitors: 40, newVisitors: 29, starters: 29, completers: 10, accounts: 3, activated: 1);
    $enough = new VisitorFigures(visitors: 40, newVisitors: 30, starters: 30, completers: 10, accounts: 3, activated: 1);

    expect($thin->accountRate(30))->toBeNull()
        ->and($thin->activationRate(30))->toBeNull()
        ->and($thin->completion(30))->toBeNull()
        ->and($thin->startRate(30))->toBe(29 / 40)
        ->and($thin->accountRate())->toBe(3 / 29)
        ->and($enough->accountRate(30))->toBe(0.1)
        ->and($enough->completion(30))->toBe(1 / 3)
        ->and(VisitorFigures::rate(3, 29, 30))->toBeNull()
        ->and(VisitorFigures::rate(3, 30, 30))->toBe(0.1);
});
