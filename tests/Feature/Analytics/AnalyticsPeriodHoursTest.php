<?php

use App\Actions\Analytics\MarkAnalyticsPeriodHourAction;
use App\Actions\Analytics\RecordUserActivityAction;
use App\Actions\Analytics\RefreshAnalyticsPeriodHoursAction;
use App\Actions\Games\CaptureGameEmailAction;
use App\Actions\Games\GameEventRecorder;
use App\Actions\Games\MoveGameAttemptAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Actions\Games\RecordGameAnswerAction;
use App\Actions\Games\StartGameAttemptAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Actions\Traffic\ConfirmAccountAttributionAction;
use App\Actions\Users\PurgeUnconfirmedAccountAction;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameFlowGoal;
use App\Enums\GameFlowStepType;
use App\Enums\UnconfirmedAccountPurgeOutcome;
use App\Enums\UserActivity;
use App\Models\AccountAttribution;
use App\Models\AnalyticsDirtyPeriodHour;
use App\Models\AnalyticsPeriodHour;
use App\Models\AnalyticsPeriodHourAccount;
use App\Models\AnalyticsRollupState;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameFlow;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\User;
use App\Queries\Analytics\AnalyticsRollupGate;
use App\Queries\Games\GameMetricsQuery;
use App\Queries\Users\UnconfirmedAccountsQuery;
use App\Support\Analytics\ActivityDay;
use App\Support\Analytics\PeriodVersionFunnel;
use App\Support\Games\Play\GameVisitor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * A traffic period's funnel, summed an hour of assignments at a time
 * (analytics_period_hours): every hour counted, once closed and settled, from
 * its sums; the hours since, and an hour changed since it was counted, from
 * the assignments themselves — the same figures, on every engine, as reading
 * every assignment. Every writer of what the funnel counts tells it of a
 * change to an hour already closed, in the change's own transaction.
 */

/**
 * periodFunnel() read from every assignment and from the hours counted, each
 * compared to the other: what both return.
 *
 * @return array<int, array<int, PeriodVersionFunnel>>
 */
function hoursFunnel(GameTrafficPeriod|array $periods, ?CarbonImmutable $maturedBefore = null): array
{
    $ids = array_map(fn (GameTrafficPeriod $period): int => (int) $period->id, is_array($periods) ? $periods : [$periods]);
    $maturedBefore ??= ActivityDay::maturedBefore(CarbonImmutable::now(), 7);
    $read = function (bool $hours) use ($ids, $maturedBefore): array {
        config(['analytics.rollup.period_hours' => $hours]);
        app()->forgetScopedInstances();
        $funnels = app(GameMetricsQuery::class)->periodFunnel($ids, $maturedBefore);
        ksort($funnels);

        return array_map(function (array $versions): array {
            ksort($versions);

            return $versions;
        }, $funnels);
    };
    $fromAssignments = $read(false);
    $fromHours = $read(true);

    expect($fromHours)->toEqual($fromAssignments, 'the hours of assignments add up differently from the assignments themselves');

    return $fromHours;
}

/** The hours counted as analytics:refresh-visits counts them now; how many. */
function countPeriodHours(int $recheckDays = 0): int
{
    $counted = app(RefreshAnalyticsPeriodHoursAction::class)->handle(microtime(true) + 120, $recheckDays);
    app()->forgetScopedInstances();

    return $counted;
}

/** @return list<string> the hours marked as changed since they were counted, the earliest first */
function markedPeriodHours(): array
{
    return AnalyticsDirtyPeriodHour::query()->orderBy('assigned_hour')->get()->map(fn (AnalyticsDirtyPeriodHour $mark): string => $mark->assigned_hour->toDateTimeString())->all();
}

function periodHoursThrough(): ?string
{
    return AnalyticsRollupState::query()->find(AnalyticsRollupState::ID)?->period_hours_through?->toDateTimeString();
}

/** @return list<int> a version's funnel as a list, in PeriodVersionFunnel's order */
function funnelFigures(PeriodVersionFunnel $funnel): array
{
    return [$funnel->assigned, $funnel->started, $funnel->completed, $funnel->resultViewed, $funnel->emailed, $funnel->goal, $funnel->accounts, $funnel->matured, $funnel->activated, $funnel->attempts, $funnel->attemptsCompleted];
}

/** A guest's browser (a name), as the game sees it. */
function hoursGuest(string $name): GameVisitor
{
    return new GameVisitor(GameAudience::Anonymous, null, hash('sha256', $name), hash('sha256', $name));
}

/** A live game of one round whose flow is the steps given, with the settings given by type, and the period of its anonymous visitors. */
function hoursGame(array $steps = [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult], array $settings = []): array
{
    $draft = publishableGameDraft(rounds: 1);
    replaceFlowSteps($draft['flow'], $steps, settings: $settings);
    $version = liveGame($draft);
    $period = GameTrafficPeriod::query()->where('game_id', $version->game_id)->where('audience', GameAudience::Anonymous->value)->firstOrFail();

    return [$draft['game']->fresh(), $version, $period];
}

afterEach(function () {
    config(['analytics.rollup.period_hours' => true]);
});

it('reads each period’s funnel from the hours counted, the hours since and those changed since, as from every assignment', function () {
    $this->travelTo('2026-11-08 12:00:00');
    $draft = publishableGameDraft(rounds: 1);
    $first = liveGame($draft);
    $flow = GameFlow::factory()->create(['game_id' => $draft['game']->id]);
    replaceFlowSteps($flow, [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult]);
    $second = app(PublishGameVersionAction::class)->handle($draft['admin'], $draft['game']->fresh(), $draft['set']->id, $flow->id);
    $earlier = GameTrafficPeriod::query()->where('game_id', $first->game_id)->where('audience', GameAudience::Anonymous->value)->firstOrFail();
    $later = GameTrafficPeriod::factory()->create(['game_id' => $first->game_id, 'started_at' => '2026-11-12 00:00:00']);
    $later->versions()->attach([$first->id => ['share_percent' => 50], $second->id => ['share_percent' => 50]]);
    $replay = fn (GameAssignment $of, array $attributes = []): GameAttempt => GameAttempt::factory()->create(['version_id' => $of->version_id, 'assignment_id' => $of->id, 'visitor_hash' => $of->visitor_hash, ...$attributes]);

    // The last second of one hour and the first of the next.
    $this->travelTo('2026-11-10 09:59:59');
    periodVisitor($earlier, $first, ['result' => true, 'email' => true, 'goal' => true]);
    $player = periodVisitor($earlier, $first, ['started' => true]);
    $replay($player, ['status' => 'completed', 'completed_at' => now()]);
    $this->travelTo('2026-11-10 10:00:00');
    periodVisitor($earlier, $first);
    // Accounts signed up the day before the cut, on it, and one never confirmed.
    periodVisitor($earlier, $first, ['account' => '2026-11-10 23:59:59', 'activated' => true]);
    periodVisitor($earlier, $first, ['account' => '2026-11-11 00:00:00', 'activated' => true]);
    periodVisitor($earlier, $first, ['account' => '2026-11-10 12:00:00', 'confirmed' => false]);
    // Two confirmed accounts credited to one play: the play's one account is the lower id's.
    $twice = periodVisitor($earlier, $first, ['account' => '2026-11-10 11:00:00']);
    AccountAttribution::factory()->confirmed()->create(['credited_attempt_id' => GameAttempt::query()->where('assignment_id', $twice->id)->value('id'), 'signed_up_at' => '2026-11-12 11:00:00', 'activated_at' => '2026-11-13 11:00:00']);
    // A play from no assignment is no period's.
    GameAttempt::factory()->create(['version_id' => $first->id]);

    $this->travelTo('2026-11-13 15:30:00');
    periodVisitor($later, $first, ['completed' => true]);
    periodVisitor($later, $second, ['result' => true, 'account' => '2026-11-13 16:00:00', 'activated' => true]);
    periodVisitor($later, $second);

    // Today: an hour that closed at 11:00 and settled, one closed this very moment, and the open one.
    $this->travelTo('2026-11-18 09:40:00');
    periodVisitor($later, $second, ['completed' => true]);
    $this->travelTo('2026-11-18 10:20:00');
    periodVisitor($later, $second, ['started' => true]);
    $this->travelTo('2026-11-18 12:00:00');
    periodVisitor($later, $first, ['goal' => true]);

    expect(countPeriodHours())->toBe(4)
        ->and(periodHoursThrough())->toBe('2026-11-18 09:00:00')
        ->and(AnalyticsPeriodHour::query()->distinct()->orderBy('assigned_hour')->pluck('assigned_hour')->map(fn (mixed $hour): string => CarbonImmutable::parse($hour)->toDateTimeString())->all())
        ->toBe(['2026-11-10 09:00:00', '2026-11-10 10:00:00', '2026-11-13 15:00:00', '2026-11-18 09:00:00']);

    $funnels = hoursFunnel([$earlier, $later]);

    expect(funnelFigures($funnels[$earlier->id][$first->id]))->toBe([7, 6, 2, 1, 1, 1, 3, 2, 1, 7, 2])
        ->and(funnelFigures($funnels[$later->id][$first->id]))->toBe([2, 2, 1, 0, 0, 1, 0, 0, 0, 2, 1])
        ->and(funnelFigures($funnels[$later->id][$second->id]))->toBe([4, 3, 2, 1, 0, 0, 1, 0, 0, 3, 2]);

    // A cut whose day is before every account, and one after them all.
    hoursFunnel([$earlier, $later], CarbonImmutable::parse('2026-11-01 00:00:00'));
    hoursFunnel([$earlier, $later], CarbonImmutable::parse('2026-11-20 00:00:00'));
});

it('reads the sums of the hours counted, the assignments of every hour after them, and every assignment for a cut inside a day or with the setting off', function () {
    $this->travelTo('2026-11-10 10:15:00');
    [, $version, $period] = hoursGame();
    periodVisitor($period, $version, ['completed' => true]);
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();
    periodVisitor($period, $version);

    // A sum changed behind its back is what the funnel reads; the assignment of the open hour is read too.
    AnalyticsPeriodHour::query()->update(['assigned' => 100]);
    app()->forgetScopedInstances();
    $read = fn (?CarbonImmutable $cut = null): int => app(GameMetricsQuery::class)->periodFunnel([(int) $period->id], $cut ?? CarbonImmutable::parse('2026-11-05 00:00:00'))[$period->id][$version->id]->assigned;

    expect($read())->toBe(101)
        ->and($read(CarbonImmutable::parse('2026-11-05 12:00:00')))->toBe(2);

    config(['analytics.rollup.period_hours' => false]);
    app()->forgetScopedInstances();

    expect($read())->toBe(2);
});

it('reads every assignment while more hours wait to be counted again than a refresh counts in a minute', function () {
    $this->travelTo('2026-11-10 10:15:00');
    [, $version, $period] = hoursGame();
    periodVisitor($period, $version);
    $this->travelTo('2026-11-18 12:00:00');
    countPeriodHours();
    AnalyticsPeriodHour::query()->update(['assigned' => 100]);
    $mark = fn (int $hours) => AnalyticsDirtyPeriodHour::query()->create(['assigned_hour' => CarbonImmutable::parse('2026-11-12 00:00:00')->addHours($hours), 'token' => (string) Str::ulid(), 'marked_at' => now()]);
    $read = function () use ($period, $version): int {
        app()->forgetScopedInstances();

        return app(GameMetricsQuery::class)->periodFunnel([(int) $period->id], CarbonImmutable::parse('2026-11-11'))[$period->id][$version->id]->assigned;
    };

    foreach (range(1, AnalyticsRollupGate::MOST_CHANGED_HOURS) as $hours) {
        $mark($hours);
    }

    expect($read())->toBe(100);

    $mark(AnalyticsRollupGate::MOST_CHANGED_HOURS + 1);

    expect($read())->toBe(1);
});

it('counts the hours from the first assignment’s, passing over hours with none, up to the last one closed and settled', function () {
    $this->travelTo('2026-11-10 08:00:00');
    [, $version, $period] = hoursGame();

    // Nothing assigned yet: every hour up to the last that can be counted is, with nothing in it.

    expect(countPeriodHours())->toBe(0)
        ->and(periodHoursThrough())->toBe('2026-11-10 05:00:00');

    $this->travelTo('2026-11-10 09:30:00');
    periodVisitor($period, $version);
    $this->travelTo('2026-11-10 14:05:00');
    periodVisitor($period, $version, ['started' => true]);

    // 09:00 closed at 11:00 and settled at 11:10; 14:00 is open.
    $this->travelTo('2026-11-10 16:09:59');

    expect(countPeriodHours())->toBe(1)
        ->and(periodHoursThrough())->toBe('2026-11-10 13:00:00')
        ->and(AnalyticsPeriodHour::query()->sole()->only(['assigned_hour', 'assigned', 'started']))->toBe(['assigned_hour' => CarbonImmutable::parse('2026-11-10 09:00:00')->toDateTimeString(), 'assigned' => 1, 'started' => 0]);

    $this->travelTo('2026-11-10 16:10:00');

    expect(countPeriodHours())->toBe(1)
        ->and(periodHoursThrough())->toBe('2026-11-10 14:00:00')
        ->and(AnalyticsPeriodHour::query()->count())->toBe(2);

    hoursFunnel($period);

    // A run out of time counts nothing more and stays where it was.
    $this->travelTo('2026-11-11 16:10:00');

    expect(app(RefreshAnalyticsPeriodHoursAction::class)->handle(microtime(true) - 1))->toBe(0)
        ->and(periodHoursThrough())->toBe('2026-11-10 14:00:00');
});

it('fills the hours of every assignment made before them, the earliest first, a run at a time', function () {
    $this->travelTo('2026-11-01 08:00:00');
    [, $version, $period] = hoursGame();

    foreach (['2026-11-01 08:10:00', '2026-11-01 08:50:00', '2026-11-03 23:59:59', '2026-11-04 00:00:00'] as $at) {
        $this->travelTo($at);
        periodVisitor($period, $version, ['completed' => true]);
    }

    $this->travelTo('2026-11-18 12:00:00');
    config(['analytics.rollup.period_hours' => true]);
    app()->forgetScopedInstances();

    // Until the first hour is counted, every assignment is read.
    expect(app(GameMetricsQuery::class)->periodFunnel([(int) $period->id], CarbonImmutable::parse('2026-11-11'))[$period->id][$version->id]->assigned)->toBe(4)
        ->and(countPeriodHours())->toBe(3)
        ->and(periodHoursThrough())->toBe('2026-11-18 09:00:00')
        ->and(AnalyticsPeriodHour::query()->orderBy('assigned_hour')->pluck('assigned')->all())->toBe([2, 1, 1]);

    hoursFunnel($period);
});

it('reads an hour changed since it was counted from its assignments until it is counted again, a change made meanwhile keeping it marked', function () {
    $this->travelTo('2026-11-10 10:15:00');
    [, $version, $period] = hoursGame();
    $assignment = periodVisitor($period, $version, ['started' => true]);
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();

    // A play no writer told of stays out of the sums: the funnel reads the hour's sums.
    GameAttempt::factory()->create(['version_id' => $version->id, 'assignment_id' => $assignment->id, 'visitor_hash' => $assignment->visitor_hash]);
    app()->forgetScopedInstances();

    expect(app(GameMetricsQuery::class)->periodFunnel([(int) $period->id], CarbonImmutable::parse('2026-11-05'))[$period->id][$version->id]->attempts)->toBe(1);

    // Told, the hour is read from its assignments.
    app(MarkAnalyticsPeriodHourAction::class)->ofAttempt(GameAttempt::query()->where('assignment_id', $assignment->id)->firstOrFail());

    expect(markedPeriodHours())->toBe(['2026-11-10 10:00:00'])
        ->and(hoursFunnel($period)[$period->id][$version->id]->attempts)->toBe(2);

    // A change made while the hour is counted leaves it marked for the next run.
    $remarked = false;
    DB::listen(function (QueryExecuted $query) use (&$remarked): void {
        if (! $remarked && str_contains($query->sql, 'game_assignments') && str_contains($query->sql, 'insert') === false && str_contains($query->sql, 'analytics_dirty_period_hours') === false) {
            $remarked = true;
            app(MarkAnalyticsPeriodHourAction::class)->handle(CarbonImmutable::parse('2026-11-10 10:30:00'));
        }
    });

    countPeriodHours();

    expect($remarked)->toBeTrue()
        ->and(markedPeriodHours())->toBe(['2026-11-10 10:00:00'])
        ->and(AnalyticsPeriodHour::query()->sole()->attempts)->toBe(2);

    countPeriodHours();

    expect(markedPeriodHours())->toBe([])
        ->and(hoursFunnel($period)[$period->id][$version->id]->attempts)->toBe(2);
});

it('marks an hour only once it closed, with a new token each time, keeping when it was first marked', function () {
    $mark = app(MarkAnalyticsPeriodHourAction::class);
    $now = CarbonImmutable::parse('2026-11-18 12:00:00');

    $mark->handle(CarbonImmutable::parse('2026-11-18 10:00:00'), $now->subSecond());
    $mark->handle(CarbonImmutable::parse('2026-11-18 11:59:59'), $now);
    $mark->handle(null, $now);

    expect(markedPeriodHours())->toBe([]);

    $mark->handle(CarbonImmutable::parse('2026-11-18 10:59:59'), $now);
    $token = AnalyticsDirtyPeriodHour::query()->sole()->token;
    $mark->handle(CarbonImmutable::parse('2026-11-18 10:00:00'), $now->addMinutes(5));

    expect(markedPeriodHours())->toBe(['2026-11-18 10:00:00'])
        ->and(AnalyticsDirtyPeriodHour::query()->sole()->token)->not->toBe($token)
        ->and(AnalyticsDirtyPeriodHour::query()->sole()->marked_at->toDateTimeString())->toBe('2026-11-18 12:00:00');
});

it('counts the hours of the last days again at night, a check that nothing was missed', function () {
    $this->travelTo('2026-11-10 10:15:00');
    [, $version, $period] = hoursGame();
    periodVisitor($period, $version, ['completed' => true]);
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();
    AnalyticsPeriodHour::query()->update(['completed' => 0]);

    countPeriodHours();

    expect(AnalyticsPeriodHour::query()->sole()->completed)->toBe(0);

    countPeriodHours(recheckDays: 3);

    expect(AnalyticsPeriodHour::query()->sole()->completed)->toBe(1);
});

it('is counted by analytics:refresh-visits before the visits', function () {
    $this->travelTo('2026-11-10 10:15:00');
    [, $version, $period] = hoursGame();
    periodVisitor($period, $version);
    $this->travelTo('2026-11-12 12:00:00');

    $this->artisan('analytics:refresh-visits')->assertSuccessful();

    expect(periodHoursThrough())->toBe('2026-11-12 09:00:00')
        ->and(AnalyticsPeriodHour::query()->sole()->assigned)->toBe(1);
});

it('reads a new assignment at once: its hour is open', function () {
    $this->travelTo('2026-11-10 10:15:00');
    [$game, $version, $period] = hoursGame();
    app(StartGameAttemptAction::class)->handle($game, hoursGuest('ann'), 'en');
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();

    app(StartGameAttemptAction::class)->handle($game, hoursGuest('bob'), 'en');

    expect(markedPeriodHours())->toBe([])
        ->and(funnelFigures(hoursFunnel($period)[$period->id][$version->id]))->toBe([2, 2, 0, 0, 0, 0, 0, 0, 0, 2, 0]);
});

it('counts a play started on an assignment of an hour already counted', function () {
    $this->travelTo('2026-11-10 10:15:00');
    [$game, $version, $period] = hoursGame();
    app(StartGameAttemptAction::class)->handle($game, hoursGuest('ann'), 'en');
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();

    // Back two days later: the same assignment, a play more.
    app(StartGameAttemptAction::class)->handle($game, hoursGuest('ann'), 'en', replay: true);

    expect(GameAssignment::query()->count())->toBe(1)
        ->and(markedPeriodHours())->toBe(['2026-11-10 10:00:00'])
        ->and(hoursFunnel($period)[$period->id][$version->id]->attempts)->toBe(2);

    countPeriodHours();

    expect(markedPeriodHours())->toBe([])
        ->and(AnalyticsPeriodHour::query()->sole()->attempts)->toBe(2)
        ->and(hoursFunnel($period)[$period->id][$version->id]->attempts)->toBe(2);
});

it('counts an attempt completed, and the result it reached, on an assignment of an hour already counted', function () {
    $this->travelTo('2026-11-10 10:15:00');
    [$game, $version, $period] = hoursGame();
    $attempt = app(StartGameAttemptAction::class)->handle($game, hoursGuest('ann'), 'en');
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();

    app(RecordGameAnswerAction::class)->handle($attempt, hoursGuest('ann'), 0, 0);

    expect(markedPeriodHours())->toBe(['2026-11-10 10:00:00'])
        ->and(funnelFigures(hoursFunnel($period)[$period->id][$version->id]))->toBe([1, 1, 1, 1, 0, 0, 0, 0, 0, 1, 1]);

    countPeriodHours();

    expect(markedPeriodHours())->toBe([])
        ->and(funnelFigures(hoursFunnel($period)[$period->id][$version->id]))->toBe([1, 1, 1, 1, 0, 0, 0, 0, 0, 1, 1]);
});

it('counts an email left, and the result seen after it, on an assignment of an hour already counted', function () {
    $this->travelTo('2026-11-10 10:15:00');
    [$game, $version, $period] = hoursGame([GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::EmailCapture, GameFlowStepType::FullResult]);
    $attempt = app(StartGameAttemptAction::class)->handle($game, hoursGuest('ann'), 'en');
    app(RecordGameAnswerAction::class)->handle($attempt, hoursGuest('ann'), 0, 0);
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();

    expect(hoursFunnel($period)[$period->id][$version->id]->resultViewed)->toBe(0);

    app(CaptureGameEmailAction::class)->handle($attempt, hoursGuest('ann'), 'ann@example.test', false);

    expect(markedPeriodHours())->toBe(['2026-11-10 10:00:00'])
        ->and(hoursFunnel($period)[$period->id][$version->id]->emailed)->toBe(1);

    countPeriodHours();
    app(MoveGameAttemptAction::class)->handle($attempt->fresh(), hoursGuest('ann'), (string) $attempt->fresh()->current_step_key);

    expect(markedPeriodHours())->toBe(['2026-11-10 10:00:00'])
        ->and(hoursFunnel($period)[$period->id][$version->id]->resultViewed)->toBe(1);

    countPeriodHours();

    expect(markedPeriodHours())->toBe([])
        ->and(funnelFigures(hoursFunnel($period)[$period->id][$version->id]))->toBe([1, 1, 1, 1, 1, 1, 0, 0, 0, 1, 1]);
});

it('counts a result seen whichever action records it', function () {
    $this->travelTo('2026-11-10 10:15:00');
    [, $version, $period] = hoursGame();
    $assignment = periodVisitor($period, $version, ['completed' => true]);
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();

    $attempt = GameAttempt::query()->where('assignment_id', $assignment->id)->firstOrFail();
    app(GameEventRecorder::class)->record(GameEventType::ResultViewed, (int) $attempt->game_id, hoursGuest('anyone'), attempt: $attempt);

    expect(markedPeriodHours())->toBe(['2026-11-10 10:00:00'])
        ->and(hoursFunnel($period)[$period->id][$version->id]->resultViewed)->toBe(1);
});

it('counts an account confirmed days after the play, and the goal it reaches, on an assignment of an hour already counted', function () {
    $this->travelTo('2026-11-10 10:15:00');
    // A flow that asks for an account after the result: an account is its goal.
    [$game, $version, $period] = hoursGame([GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Registration], ['full_result' => ['signup' => 'step']]);
    $attempt = app(StartGameAttemptAction::class)->handle($game, hoursGuest('ann'), 'en');
    $member = User::factory()->create();

    expect($version->flowGoal())->toBe(GameFlowGoal::Account);

    AccountAttribution::factory()->create(['user_id' => $member->id, 'credited_attempt_id' => $attempt->id, 'signed_up_at' => '2026-11-10 10:20:00']);
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();

    expect(hoursFunnel($period)[$period->id][$version->id]->accounts)->toBe(0);

    app(ConfirmAccountAttributionAction::class)->handle($member->id, CarbonImmutable::now());

    expect(markedPeriodHours())->toBe(['2026-11-10 10:00:00'])
        ->and(funnelFigures(hoursFunnel($period)[$period->id][$version->id]))->toBe([1, 1, 0, 0, 0, 1, 1, 0, 0, 1, 0]);

    countPeriodHours();

    expect(markedPeriodHours())->toBe([])
        ->and(AnalyticsPeriodHourAccount::query()->sole()->only(['signup_day', 'accounts', 'activated']))->toBe(['signup_day' => '2026-11-10', 'accounts' => 1, 'activated' => 0])
        ->and(funnelFigures(hoursFunnel($period)[$period->id][$version->id]))->toBe([1, 1, 0, 0, 0, 1, 1, 0, 0, 1, 0]);
});

it('counts an account activated days after the play on an assignment of an hour already counted, once it matured', function () {
    $this->travelTo('2026-11-10 10:15:00');
    [, $version, $period] = hoursGame();
    $assignment = periodVisitor($period, $version, ['account' => '2026-11-10 10:20:00']);
    $member = User::query()->findOrFail(AccountAttribution::query()->sole()->user_id);
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();

    app(RecordUserActivityAction::class)->handle($member, UserActivity::Played, null, null, CarbonImmutable::now());

    expect(AccountAttribution::query()->sole()->activated_at)->not->toBeNull()
        ->and(markedPeriodHours())->toBe(['2026-11-10 10:00:00']);

    $this->travelTo('2026-11-18 12:00:00');

    expect(funnelFigures(hoursFunnel($period)[$period->id][$version->id]))->toBe([1, 1, 0, 0, 0, 0, 1, 1, 1, 1, 0]);

    countPeriodHours();

    expect(markedPeriodHours())->toBe([])
        ->and(GameAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue()
        ->and(funnelFigures(hoursFunnel($period)[$period->id][$version->id]))->toBe([1, 1, 0, 0, 0, 0, 1, 1, 1, 1, 0]);
});

it('counts the results seen and emails purged with their retention', function () {
    $this->travelTo('2025-10-01 10:15:00');
    [$game, $version, $period] = hoursGame([GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::EmailCapture, GameFlowStepType::FullResult]);
    $attempt = app(StartGameAttemptAction::class)->handle($game, hoursGuest('ann'), 'en');
    app(RecordGameAnswerAction::class)->handle($attempt, hoursGuest('ann'), 0, 0);
    app(CaptureGameEmailAction::class)->handle($attempt, hoursGuest('ann'), 'ann@example.test', false);
    app(MoveGameAttemptAction::class)->handle($attempt->fresh(), hoursGuest('ann'), (string) $attempt->fresh()->current_step_key);
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();

    expect(funnelFigures(hoursFunnel($period)[$period->id][$version->id]))->toBe([1, 1, 1, 1, 1, 1, 0, 0, 0, 1, 1]);

    $this->artisan('games:purge-expired')->assertSuccessful();

    expect(markedPeriodHours())->toBe(['2025-10-01 10:00:00'])
        ->and(funnelFigures(hoursFunnel($period)[$period->id][$version->id]))->toBe([1, 1, 1, 0, 0, 1, 0, 0, 0, 1, 1]);

    countPeriodHours();

    expect(markedPeriodHours())->toBe([])
        ->and(funnelFigures(hoursFunnel($period)[$period->id][$version->id]))->toBe([1, 1, 1, 0, 0, 1, 0, 0, 0, 1, 1]);
});

it('counts the plays and assignments of an unconfirmed account removed with everything it did', function () {
    $this->travelTo('2026-11-01 10:15:00');
    [$game, $version, $period] = hoursGame();
    $members = GameTrafficPeriod::query()->where('game_id', $game->id)->where('audience', GameAudience::Authenticated->value)->firstOrFail();
    $bot = User::factory()->unverified()->create(['created_at' => now()]);
    // Its own plays, signed in, and a guest's play it took on signing up.
    periodVisitor($members, $version, ['player' => $bot, 'completed' => true]);
    $claimed = periodVisitor($period, $version, ['completed' => true]);
    GameAttempt::query()->where('assignment_id', $claimed->id)->update(['user_id' => $bot->id]);
    periodVisitor($period, $version, ['started' => true]);
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();

    expect(app(PurgeUnconfirmedAccountAction::class)->handle((int) $bot->id, UnconfirmedAccountsQuery::cutoff()))->toBe(UnconfirmedAccountPurgeOutcome::Purged)
        ->and(markedPeriodHours())->toBe(['2026-11-01 10:00:00']);

    $funnels = hoursFunnel([$period, $members]);

    expect(funnelFigures($funnels[$period->id][$version->id]))->toBe([2, 1, 0, 0, 0, 0, 0, 0, 0, 1, 0])
        ->and($funnels)->not->toHaveKey($members->id);

    countPeriodHours();

    expect(markedPeriodHours())->toBe([]);

    hoursFunnel([$period, $members]);
});

it('counts the emails of an account’s plays gone when the account is deleted', function () {
    $this->travelTo('2026-11-01 10:15:00');
    [, $version, $period] = hoursGame();
    $member = User::factory()->create();
    $assignment = periodVisitor($period, $version, ['email' => true]);
    GameAttempt::query()->where('assignment_id', $assignment->id)->update(['user_id' => $member->id]);
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();

    app(AnonymizeUserAccountAction::class)->execute($member);

    expect(markedPeriodHours())->toBe(['2026-11-01 10:00:00'])
        ->and(hoursFunnel($period)[$period->id][$version->id]->emailed)->toBe(0);

    countPeriodHours();

    expect(markedPeriodHours())->toBe([])
        ->and(hoursFunnel($period)[$period->id][$version->id]->emailed)->toBe(0);
});

it('marks nothing for a version given to nobody and reads no period at all', function () {
    expect(app(GameMetricsQuery::class)->periodFunnel([], CarbonImmutable::now()))->toBe([]);

    $version = GameVersion::factory()->create();
    $period = GameTrafficPeriod::factory()->create(['game_id' => $version->game_id]);
    $this->travelTo('2026-11-12 12:00:00');
    countPeriodHours();

    expect(hoursFunnel($period))->toBe([]);
});
