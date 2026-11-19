<?php

use App\Enums\ComparisonEvidence;
use App\Enums\GameAudience;
use App\Enums\PeriodDataState;
use App\Enums\PeriodMetric;
use App\Support\Analytics\ActivityDay;
use App\Support\Analytics\ComparedVersion;
use App\Support\Analytics\PeriodComparison;
use App\Support\Analytics\PeriodVersionFunnel;
use Carbon\CarbonImmutable;

/*
 * A traffic period's versions compared: every rate a share of the visitors
 * assigned to the version; the reference the version with the largest share,
 * the lower number among equals; differences in percentage points; whether
 * there is enough data from the primary metric — accounts for anonymous
 * visitors, the goal for logged-in players — at ten on every version; why a
 * rate can't be read; and how sure a difference is.
 */

function comparedFunnel(int $assigned, int $accounts = 0, int $goal = 0, array $more = []): PeriodVersionFunnel
{
    return new PeriodVersionFunnel(...['assigned' => $assigned, 'accounts' => $accounts, 'goal' => $goal, 'matured' => $accounts, ...$more]);
}

it('takes the version with the largest share as the reference, the lower number among equals', function () {
    $v7 = new ComparedVersion(7, 7, 50);
    $v8 = new ComparedVersion(8, 8, 50);
    $v9 = new ComparedVersion(9, 9, 20);

    expect((new PeriodComparison(GameAudience::Anonymous, [$v9, $v8, $v7]))->reference())->toBe($v7)
        ->and((new PeriodComparison(GameAudience::Anonymous, [new ComparedVersion(7, 7, 20), new ComparedVersion(8, 8, 80)]))->reference()?->number)->toBe(8)
        ->and((new PeriodComparison(GameAudience::Anonymous, []))->reference())->toBeNull()
        // Listed in version order whatever order they come in.
        ->and(array_map(fn (ComparedVersion $version): string => $version->label(), (new PeriodComparison(GameAudience::Anonymous, [$v9, $v7, $v8]))->versions))->toBe(['V7', 'V8', 'V9']);
});

it('reads every rate from the visitors assigned, and its difference from the reference in percentage points', function () {
    $v7 = new ComparedVersion(7, 7, 80);
    $v8 = new ComparedVersion(8, 8, 20);
    $comparison = new PeriodComparison(GameAudience::Anonymous, [$v7, $v8], [
        7 => comparedFunnel(1000, more: ['started' => 780]),
        8 => comparedFunnel(400, more: ['started' => 300]),
    ]);

    expect($comparison->rate($v7, PeriodMetric::Starts))->toBe(78.0)
        ->and($comparison->rate($v8, PeriodMetric::Starts))->toBe(75.0)
        ->and($comparison->delta($v8, PeriodMetric::Starts))->toBe(-3.0)
        // The reference is what the others are measured against: no difference of its own.
        ->and($comparison->delta($v7, PeriodMetric::Starts))->toBeNull()
        ->and($comparison->evidence($v7, PeriodMetric::Starts))->toBeNull();
});

it('tells the four states apart', function (GameAudience $audience, array $versions, array $funnels, PeriodDataState $state) {
    expect((new PeriodComparison($audience, $versions, $funnels))->state())->toBe($state);
})->with([
    'one version' => [GameAudience::Anonymous, [new ComparedVersion(7, 7, 100)], [7 => comparedFunnel(5000, 400)], PeriodDataState::Single],
    'nobody sent yet' => [GameAudience::Anonymous, [new ComparedVersion(7, 7, 50), new ComparedVersion(8, 8, 50)], [], PeriodDataState::Empty],
    'a version short of ten accounts' => [GameAudience::Anonymous, [new ComparedVersion(7, 7, 50), new ComparedVersion(8, 8, 50)], [7 => comparedFunnel(900, 10), 8 => comparedFunnel(900, 9)], PeriodDataState::Early],
    'ten accounts on each' => [GameAudience::Anonymous, [new ComparedVersion(7, 7, 50), new ComparedVersion(8, 8, 50)], [7 => comparedFunnel(900, 10), 8 => comparedFunnel(900, 10)], PeriodDataState::Enough],
    'a version nobody was sent to yet, the other plenty' => [GameAudience::Anonymous, [new ComparedVersion(7, 7, 50), new ComparedVersion(8, 8, 50)], [7 => comparedFunnel(900, 40)], PeriodDataState::Early],
    'logged-in players: by the goal, never accounts' => [GameAudience::Authenticated, [new ComparedVersion(7, 7, 50), new ComparedVersion(8, 8, 50)], [7 => comparedFunnel(300, 0, 12), 8 => comparedFunnel(300, 0, 10)], PeriodDataState::Enough],
    'logged-in players short of the goal' => [GameAudience::Authenticated, [new ComparedVersion(7, 7, 50), new ComparedVersion(8, 8, 50)], [7 => comparedFunnel(300, 50, 12), 8 => comparedFunnel(300, 50, 3)], PeriodDataState::Early],
]);

it('names the versions short of the primary metric while it is too early', function () {
    $versions = [new ComparedVersion(7, 7, 50), new ComparedVersion(8, 8, 30), new ComparedVersion(9, 9, 20)];

    expect((new PeriodComparison(GameAudience::Anonymous, $versions, [7 => comparedFunnel(500, 12), 8 => comparedFunnel(300, 4), 9 => comparedFunnel(200, 1)]))->earlyNote())
        ->toBe('Too early: V8 has 4 of 10 accounts, V9 has 1 of 10 accounts')
        ->and((new PeriodComparison(GameAudience::Authenticated, $versions, [7 => comparedFunnel(500, 0, 3), 8 => comparedFunnel(300, 0, 10), 9 => comparedFunnel(200, 0, 11)]))->earlyNote())
        ->toBe('Too early: V7 has 3 of 10 players who reached the goal')
        ->and((new PeriodComparison(GameAudience::Anonymous, $versions, [7 => comparedFunnel(500, 12), 8 => comparedFunnel(300, 10), 9 => comparedFunnel(200, 10)]))->earlyNote())->toBeNull();
});

it('says why a rate can’t be read', function () {
    $asks = new ComparedVersion(7, 7, 50, asksForEmail: true);
    $silent = new ComparedVersion(8, 8, 30);
    $unsent = new ComparedVersion(9, 9, 20, asksForEmail: true);
    $anonymous = new PeriodComparison(GameAudience::Anonymous, [$asks, $silent, $unsent], [
        7 => comparedFunnel(500, 20, more: ['emailed' => 40, 'matured' => 0]),
        8 => comparedFunnel(300, 0, more: ['matured' => 0]),
    ]);
    $players = new PeriodComparison(GameAudience::Authenticated, [$asks, $silent], [7 => comparedFunnel(300, 0, 12), 8 => comparedFunnel(300, 0, 10)]);
    $accountless = new PeriodComparison(GameAudience::Anonymous, [$asks, $silent], [7 => comparedFunnel(500, 0), 8 => comparedFunnel(300, 0)]);

    expect($anonymous->dash($asks, PeriodMetric::Emails))->toBeNull()
        ->and($anonymous->dash($silent, PeriodMetric::Emails))->toBe('Not in this flow')
        // Accounts in the period, none of them past their seven days yet: every version waits, one without accounts too.
        ->and($anonymous->dash($asks, PeriodMetric::Activated))->toBe('Needs 7 days')
        ->and($anonymous->dash($silent, PeriodMetric::Activated))->toBe('Needs 7 days')
        // No account in the whole period: nothing to wait for, none activated.
        ->and($accountless->dash($silent, PeriodMetric::Activated))->toBeNull()
        ->and($accountless->rate($silent, PeriodMetric::Activated))->toBe(0.0)
        ->and($anonymous->dash($unsent, PeriodMetric::Starts))->toBe('No visitors yet')
        ->and($anonymous->rate($unsent, PeriodMetric::Starts))->toBeNull()
        // Logged-in players are signed up already, and never asked for an email.
        ->and($players->dash($asks, PeriodMetric::Registrations))->toBe('Already signed up')
        ->and($players->dash($asks, PeriodMetric::Activated))->toBe('Already signed up')
        ->and($players->dash($asks, PeriodMetric::Emails))->toBe('Not in this flow')
        ->and($players->dash($asks, PeriodMetric::Goal))->toBeNull()
        // A difference needs both rates.
        ->and($anonymous->delta($silent, PeriodMetric::Emails))->toBeNull();
});

it('judges how sure a difference from the reference is', function () {
    $v7 = new ComparedVersion(7, 7, 50, asksForEmail: false);
    $v8 = new ComparedVersion(8, 8, 50, asksForEmail: true);
    $v9 = new ComparedVersion(9, 9, 10);
    $comparison = new PeriodComparison(GameAudience::Anonymous, [$v7, $v8, $v9], [
        // Starts: 50 % against 60 % of 1 000 each is far past 95 % confidence; completions 10.0 % against 10.5 % is not.
        7 => comparedFunnel(1000, 100, more: ['started' => 500, 'completed' => 100, 'goal' => 100]),
        8 => comparedFunnel(1000, 120, more: ['started' => 600, 'completed' => 105, 'emailed' => 300, 'goal' => 125]),
        9 => comparedFunnel(100, 5, more: ['started' => 50]),
    ]);

    expect($comparison->evidence($v8, PeriodMetric::Starts))->toBe(ComparisonEvidence::Strong)
        ->and($comparison->evidence($v8, PeriodMetric::Completions))->toBe(ComparisonEvidence::None)
        // 12.5 % against 10 %: about 92 % sure.
        ->and($comparison->evidence($v8, PeriodMetric::Goal))->toBe(ComparisonEvidence::Ahead)
        // 10 % against the reference's 12.5 %, as sure: behind, not ahead.
        ->and((new PeriodComparison(GameAudience::Anonymous, [$v7, $v8], [
            7 => comparedFunnel(1000, 100, more: ['goal' => 125]),
            8 => comparedFunnel(1000, 100, more: ['goal' => 100]),
        ]))->evidence($v8, PeriodMetric::Goal))->toBe(ComparisonEvidence::Behind)
        // The reference's flow asks for no email: nothing to compare, by design.
        ->and($comparison->evidence($v8, PeriodMetric::Emails))->toBe(ComparisonEvidence::ByDesign)
        // Fewer than ten conversions on a side.
        ->and($comparison->evidence($v9, PeriodMetric::Registrations))->toBe(ComparisonEvidence::TooEarly);

    $players = new PeriodComparison(GameAudience::Authenticated, [$v7, $v8], [7 => comparedFunnel(300, 0, 12), 8 => comparedFunnel(300, 0, 10)]);

    expect($players->evidence($v8, PeriodMetric::Registrations))->toBe(ComparisonEvidence::ByDesign)
        ->and((new PeriodComparison(GameAudience::Anonymous, [$v7, $v8], [7 => comparedFunnel(1000, 100)]))->evidence($v8, PeriodMetric::Starts))->toBe(ComparisonEvidence::TooEarly)
        ->and((new PeriodComparison(GameAudience::Anonymous, [$v7]))->evidence($v7, PeriodMetric::Starts))->toBeNull();
});

it('judges a metric the reference’s flow leaves out by design, even while the version has no visitors yet', function () {
    $v7 = new ComparedVersion(7, 7, 80, asksForEmail: false);
    $v8 = new ComparedVersion(8, 8, 20, asksForEmail: true);
    $comparison = new PeriodComparison(GameAudience::Anonymous, [$v7, $v8], [7 => comparedFunnel(1000)]);

    expect($comparison->evidence($v8, PeriodMetric::Emails))->toBe(ComparisonEvidence::ByDesign)
        // A metric both flows have waits for the version's visitors.
        ->and($comparison->evidence($v8, PeriodMetric::Starts))->toBe(ComparisonEvidence::TooEarly);
});

it('counts an account matured once its seventh day after signing up is over, in UTC', function () {
    expect(ActivityDay::maturedBefore(CarbonImmutable::parse('2026-11-18 00:00:01'), 7)->toDateTimeString())->toBe('2026-11-11 00:00:00')
        ->and(ActivityDay::maturedBefore(CarbonImmutable::parse('2026-11-18 23:59:59'), 7)->toDateTimeString())->toBe('2026-11-11 00:00:00')
        ->and(ActivityDay::maturedBefore(CarbonImmutable::parse('2026-11-18 00:30:00', 'Europe/Berlin'), 7)->toDateTimeString())->toBe('2026-11-10 00:00:00');
});

it('reads a version’s difference relative to the reference, and the rates among those who got as far as another step', function () {
    $v7 = new ComparedVersion(7, 7, 50);
    $v8 = new ComparedVersion(8, 8, 50, asksForEmail: true);
    $comparison = new PeriodComparison(GameAudience::Anonymous, [$v7, $v8], [
        7 => comparedFunnel(1000, 50, more: ['completed' => 500, 'resultViewed' => 480, 'goal' => 0]),
        8 => comparedFunnel(1000, 40, more: ['completed' => 200, 'resultViewed' => 150, 'emailed' => 100, 'goal' => 30]),
    ]);

    expect($comparison->rate($v8, PeriodMetric::ResultViewed))->toBe(15.0)
        // 4 % against 5 %: a fifth less.
        ->and($comparison->relative($v8, PeriodMetric::Registrations))->toEqualWithDelta(-20.0, 1e-9)
        ->and($comparison->relative($v7, PeriodMetric::Registrations))->toBeNull()
        // Nothing to be relative to where the reference's rate is nought, or can't be read.
        ->and($comparison->relative($v8, PeriodMetric::Goal))->toBeNull()
        ->and($comparison->relative($v8, PeriodMetric::Emails))->toBeNull()
        // Accounts among those who completed: 10 % against 20 %.
        ->and($comparison->conditional($v7, PeriodMetric::Registrations, PeriodMetric::Completions))->toBe(10.0)
        ->and($comparison->conditional($v8, PeriodMetric::Registrations, PeriodMetric::Completions))->toBe(20.0)
        ->and($comparison->conditionalRelative($v8, PeriodMetric::Registrations, PeriodMetric::Completions))->toEqualWithDelta(100.0, 1e-9)
        ->and($comparison->conditional($v7, PeriodMetric::Emails, PeriodMetric::Completions))->toBeNull()
        ->and($comparison->conditional($v8, PeriodMetric::Emails, PeriodMetric::Completions))->toBe(50.0)
        ->and($comparison->conditionalRelative($v8, PeriodMetric::Emails, PeriodMetric::Completions))->toBeNull()
        // Nobody got as far: no rate among them.
        ->and((new PeriodComparison(GameAudience::Anonymous, [$v7, $v8], [7 => comparedFunnel(10), 8 => comparedFunnel(10)]))->conditional($v7, PeriodMetric::Registrations, PeriodMetric::Completions))->toBeNull();
});
