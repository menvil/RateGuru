<?php

use App\Actions\Analytics\MarkAnalyticsGameCellsAction;
use App\Actions\Analytics\RefreshAnalyticsGameCellsAction;
use App\Actions\Games\AssignGameVersionAction;
use App\Actions\Games\ClaimGuestGameAttemptsAction;
use App\Actions\Games\GameEventRecorder;
use App\Actions\Games\PublishGameVersionAction;
use App\Actions\Games\RecordGameAnswerAction;
use App\Actions\Games\StartGameAttemptAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Actions\Traffic\ConfirmAccountAttributionAction;
use App\Actions\Users\PurgeUnconfirmedAccountAction;
use App\Enums\GameAccountOutcome;
use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameFlowGoal;
use App\Enums\GameFlowStepType;
use App\Enums\GameOnwardKind;
use App\Enums\TrafficDevice;
use App\Enums\UnconfirmedAccountPurgeOutcome;
use App\Models\AccountAttribution;
use App\Models\AnalyticsDirtyGameDay;
use App\Models\AnalyticsGameCell;
use App\Models\AnalyticsGamePid;
use App\Models\AnalyticsRollupState;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\GameFlow;
use App\Models\GameVersion;
use App\Models\User;
use App\Queries\Analytics\AnalyticsRollupGate;
use App\Queries\Games\GameMetricsQuery;
use App\Queries\Users\UnconfirmedAccountsQuery;
use App\Support\Analytics\GameCellDays;
use App\Support\Games\Analytics\GameFunnel;
use App\Support\Games\Analytics\GameMetricsScope;
use App\Support\Games\Play\GameVisitor;
use App\Support\Traffic\Reports\VisitFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The people of a game's days (analytics_game_cells): its visitors and the
 * people behind its attempts, a guest by a number given their browser's hash
 * and a player by their account's id — every day closed and settled counted
 * whole, the days since up to a moment, and whatever neither covers read from
 * the events and attempts themselves — the same figures, at any moment, as
 * reading every view and attempt of the window. Every writer of what they
 * count tells a day already counted of a change in the change's own
 * transaction. Kept only on PostgreSQL with the roaringbitmap extension;
 * elsewhere every read takes the events and attempts, and the comparisons
 * here compare that read with itself.
 */

/**
 * funnels() of the scope read from the events and attempts and from the
 * people of the games' days, each compared to the other: what both return.
 *
 * @return array<int|string, GameFunnel>
 */
function cellsFunnels(GameMetricsScope $scope, ?string $by = null): array
{
    $read = function (bool $cells) use ($scope, $by): array {
        config(['analytics.rollup.game_cells' => $cells]);
        app()->forgetScopedInstances();
        $funnels = app(GameMetricsQuery::class)->funnels($scope, $by);
        ksort($funnels);

        return $funnels;
    };
    $live = $read(false);
    $fromCells = $read(true);
    $window = $scope->from->toDateTimeString().' — '.$scope->to->toDateTimeString();

    expect($fromCells)->toEqual($live, "the people of the games' days of {$scope->audience->value} over {$window}".($by === null ? '' : " by {$by}").' add up differently from the events and attempts');

    return $fromCells;
}

/** visitors() of the scope read both ways, each compared to the other: what both return. */
function cellsVisitors(GameMetricsScope $scope): int
{
    $read = function (bool $cells) use ($scope): int {
        config(['analytics.rollup.game_cells' => $cells]);
        app()->forgetScopedInstances();

        return app(GameMetricsQuery::class)->visitors($scope);
    };
    $live = $read(false);

    expect($read(true))->toBe($live, "the people of the games' days give the visitors differently from the views");

    return $live;
}

/** The days counted as analytics:refresh-visits counts them now; how many. */
function countGameCells(int $recheckDays = 0): int
{
    $counted = app(RefreshAnalyticsGameCellsAction::class)->handle(microtime(true) + 120, $recheckDays);
    app()->forgetScopedInstances();

    return $counted;
}

/** @return list<string> the days marked as changed since they were counted, the earliest first */
function markedGameDays(): array
{
    return AnalyticsDirtyGameDay::query()->orderBy('day')->pluck('day')->map(fn (mixed $day): string => (string) $day)->all();
}

function gameCellsThrough(): ?string
{
    return AnalyticsRollupState::current()->game_cells_through;
}

/** Whether the read just made took the people of the games' days, reading no view or attempt grouped by person. */
function readGameCells(Closure $read): bool
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $read();
    $queries = implode("\n", array_column(DB::getQueryLog(), 'query'));
    DB::disableQueryLog();

    return str_contains($queries, 'analytics_game_cells') && ! str_contains($queries, 'group by "game_events"."visitor_hash"') && ! str_contains($queries, 'group by "game_attempts"."visitor_hash"');
}

function cellsScope(Game|GameVersion $game, string $from, string $to, GameAudience $audience = GameAudience::Anonymous, ?int $versionId = null, ?VisitFilter $visits = null): GameMetricsScope
{
    return new GameMetricsScope((int) ($game instanceof Game ? $game->id : $game->game_id), $audience, CarbonImmutable::parse($from, 'UTC'), CarbonImmutable::parse($to, 'UTC'), $versionId, $visits);
}

/** A guest's browser (a name), as the game sees it. */
function cellsGuest(string $name): GameVisitor
{
    return new GameVisitor(GameAudience::Anonymous, null, hash('sha256', $name), hash('sha256', $name));
}

/** A `view` of the version — or of none — at a moment by a guest (a name), a player, or nobody to tell apart (null). */
function cellsView(GameVersion $version, string|User|null $who, string $at, bool $ofVersion = true, ?GameAudience $audience = null): void
{
    (new GameEvent)->forceFill([
        'game_id' => $version->game_id,
        'version_id' => $ofVersion ? $version->id : null,
        'audience' => $audience ?? ($who instanceof User ? GameAudience::Authenticated : GameAudience::Anonymous),
        'user_id' => $who instanceof User ? $who->id : null,
        'visitor_hash' => is_string($who) ? hash('sha256', $who) : null,
        'type' => GameEventType::View,
        'occurred_at' => $at,
    ])->save();
}

/**
 * An attempt at the version begun at a moment by a guest (a name), a player,
 * or nobody to tell apart (null) of the audience given — with the event
 * every change after its start is recorded with, as the actions record it:
 * its completion, its goal, the account that took it.
 */
function cellsAttempt(GameVersion $version, string|User|null $who, string $at, array $attributes = [], ?GameAudience $audience = null): GameAttempt
{
    $attempt = GameAttempt::factory()->create([
        'version_id' => $version->id,
        'audience' => $audience ?? ($who instanceof User ? GameAudience::Authenticated : GameAudience::Anonymous),
        'user_id' => $who instanceof User ? $who->id : null,
        'visitor_hash' => is_string($who) ? hash('sha256', $who) : null,
        'started_at' => $at,
        'last_activity_at' => $at,
        ...$attributes,
    ]);

    foreach (['completed_at' => GameEventType::Completed, 'goal_reached_at' => GameEventType::GoalReached, 'account_outcome_at' => GameEventType::AccountAttributed] as $moment => $type) {
        if (isset($attributes[$moment])) {
            cellsEvent($attempt, $type, (string) $attributes[$moment]);
        }
    }

    return $attempt;
}

/** An event of an attempt at a moment — a way on, to the step given. */
function cellsEvent(GameAttempt $attempt, GameEventType $type, string $at, ?GameOnwardKind $step = null): void
{
    (new GameEvent)->forceFill([
        'game_id' => $attempt->game_id,
        'version_id' => $attempt->version_id,
        'attempt_id' => $attempt->id,
        'audience' => $attempt->audience,
        'user_id' => $attempt->user_id,
        'visitor_hash' => $attempt->visitor_hash,
        'type' => $type,
        'step_type' => $step?->value,
        'occurred_at' => $at,
    ])->save();
}

/** @return array<string, mixed> an attempt completed at a moment */
function cellsCompleted(string $at): array
{
    return ['status' => GameAttemptStatus::Completed, 'completed_at' => $at, 'matches' => 1, 'score' => 1, 'profile_key' => 'oracle'];
}

/** A live game of one round whose flow is the steps given, with the settings given by type. */
function cellsGame(array $steps = [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult], array $settings = []): GameVersion
{
    $draft = publishableGameDraft(rounds: 1);
    replaceFlowSteps($draft['flow'], $steps, settings: $settings);

    return liveGame($draft);
}

/**
 * Every figure of the game read both ways over the windows a page reads and
 * the edges of the cells — in all, by version, flow and content set, of one
 * version, and for each audience — as of now.
 *
 * @param  list<array{0: string, 1: string}>  $windows
 */
function compareGameCells(GameVersion $version, array $windows): void
{
    foreach ($windows as [$from, $to]) {
        foreach (GameAudience::cases() as $audience) {
            $scope = cellsScope($version, $from, $to, $audience);
            cellsFunnels($scope);
            cellsVisitors($scope);
            cellsFunnels(cellsScope($version, $from, $to, $audience, (int) $version->id));

            foreach (array_keys(GameMetricsQuery::GROUPS) as $by) {
                cellsFunnels($scope, $by);
            }
        }
    }
}

beforeEach(function () {
    // The cells are read only where the cells of each day's people are: as though those were filled and up to date.
    config(['analytics.rollup.stale_minutes' => 10_000_000]);
    AnalyticsRollupState::query()->upsert([[
        'id' => AnalyticsRollupState::ID,
        'filled_at' => '2020-01-01 00:00:00',
        'refreshed_at' => '2030-01-01 00:00:00',
        'cells_filled_at' => app(AnalyticsRollupGate::class)->cellsKept() ? '2020-01-01 00:00:00' : null,
    ]], ['id'], ['filled_at', 'refreshed_at', 'cells_filled_at']);
    app()->forgetScopedInstances();
});

afterEach(function () {
    config(['analytics.rollup.game_cells' => true]);
});

it('counts a game’s people from the cells of its days, the days still open up to their moment and what came since, as from the events and attempts', function () {
    $this->travelTo('2026-11-01 08:00:00');
    $draft = publishableGameDraft(rounds: 1);
    $first = liveGame($draft);
    $flow = GameFlow::factory()->create(['game_id' => $draft['game']->id]);
    replaceFlowSteps($flow, [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult]);
    $second = app(PublishGameVersionAction::class)->handle($draft['admin'], $draft['game']->fresh(), $draft['set']->id, $flow->id);
    [$ann, $bob] = [User::factory()->create(), User::factory()->create()];

    // Three days ago, closed: a guest who plays twice, one who finishes the day after, nobody to tell apart twice, a player.
    cellsView($first, 'a', '2026-11-09 08:00:00');
    cellsView($first, 'b', '2026-11-09 23:59:59');
    cellsView($second, 'c', '2026-11-09 00:00:00');
    cellsView($first, null, '2026-11-09 10:00:00');
    cellsView($first, 'a', '2026-11-09 10:00:00', ofVersion: false);
    cellsView($first, $ann, '2026-11-09 11:00:00');
    $shared = cellsAttempt($first, 'a', '2026-11-09 08:01:00', cellsCompleted('2026-11-09 08:05:00'));
    cellsEvent($shared, GameEventType::ShareClicked, '2026-11-09 08:06:00');
    cellsEvent($shared, GameEventType::ShareClicked, '2026-11-09 08:07:00');
    cellsAttempt($first, 'a', '2026-11-09 09:00:00');
    $finishedLater = cellsAttempt($second, 'b', '2026-11-09 23:59:59', cellsCompleted('2026-11-10 10:00:00'));
    cellsEvent($finishedLater, GameEventType::ShareClicked, '2026-11-10 10:01:00');
    cellsAttempt($first, null, '2026-11-09 12:00:00');
    cellsAttempt($first, null, '2026-11-09 13:00:00', cellsCompleted('2026-11-09 13:05:00'));
    cellsAttempt($first, $ann, '2026-11-09 11:01:00', ['goal_reached_at' => '2026-11-09 11:10:00']);
    $credited = cellsAttempt($first, 'b', '2026-11-09 23:00:00', cellsCompleted('2026-11-09 23:05:00'));
    AccountAttribution::factory()->create(['credited_attempt_id' => $credited->id, 'confirmed_at' => '2026-11-11 09:00:00']);
    // Yesterday: the guest again on the other version, a newcomer, a player with nobody to tell apart.
    cellsView($second, 'a', '2026-11-11 00:00:00');
    cellsView($first, 'd', '2026-11-11 15:00:00');
    cellsAttempt($second, 'a', '2026-11-11 00:00:00', cellsCompleted('2026-11-11 00:02:00'));
    cellsAttempt($first, 'c', '2026-11-11 23:58:00', ['goal_reached_at' => '2026-11-12 00:30:00', 'user_id' => $ann->id, 'account_outcome' => GameAccountOutcome::SignedIn, 'account_outcome_at' => '2026-11-12 00:10:00']);
    cellsAttempt($first, null, '2026-11-11 16:00:00', [], GameAudience::Authenticated);
    cellsView($first, $bob, '2026-11-11 16:00:00');
    // Today, before the moment and after it: plays that finish, reach the goal or have an account confirmed later.
    cellsView($first, 'e', '2026-11-12 09:00:00');
    cellsView($first, 'a', '2026-11-12 14:00:00');
    cellsView($second, 'f', '2026-11-12 14:30:00');
    cellsView($first, 'f', '2026-11-12 14:31:00');
    $finishing = cellsAttempt($first, 'e', '2026-11-12 09:01:00', [...cellsCompleted('2026-11-12 14:32:00'), 'user_id' => $bob->id, 'account_outcome' => GameAccountOutcome::SignedIn, 'account_outcome_at' => '2026-11-12 14:33:00']);
    cellsEvent($finishing, GameEventType::ShareClicked, '2026-11-12 14:32:30');
    $replay = cellsAttempt($first, 'a', '2026-11-12 14:29:00');
    cellsEvent($replay, GameEventType::RegistrationCtaClicked, '2026-11-12 14:30:00');
    $toFeed = cellsAttempt($first, 'g', '2026-11-12 10:00:00', ['goal_reached_at' => '2026-11-12 14:33:00', 'onward_kind' => GameOnwardKind::Feed, 'onward_at' => '2026-11-12 13:00:00']);
    cellsEvent($toFeed, GameEventType::Exit, '2026-11-12 13:00:00', GameOnwardKind::Feed);
    cellsEvent($toFeed, GameEventType::FeedVoted, '2026-11-12 14:34:00');
    $late = cellsAttempt($second, 'h', '2026-11-12 10:30:00', ['onward_kind' => GameOnwardKind::Post, 'onward_at' => '2026-11-12 14:31:00']);
    cellsEvent($late, GameEventType::Exit, '2026-11-12 14:31:00', GameOnwardKind::Post);
    cellsEvent($late, GameEventType::PostOpened, '2026-11-12 14:31:00');
    AccountAttribution::factory()->create(['credited_attempt_id' => $late->id, 'confirmed_at' => '2026-11-12 14:34:00']);
    $twice = cellsAttempt($second, 'i', '2026-11-12 11:00:00');
    cellsEvent($twice, GameEventType::ShareClicked, '2026-11-12 11:05:00');
    cellsEvent($twice, GameEventType::ShareClicked, '2026-11-12 14:35:00');
    AccountAttribution::factory()->create(['credited_attempt_id' => $twice->id, 'confirmed_at' => '2026-11-12 11:30:00']);
    AccountAttribution::factory()->create(['credited_attempt_id' => $twice->id, 'confirmed_at' => '2026-11-12 14:35:00']);
    cellsAttempt($first, 'j', '2026-11-12 14:30:00');
    cellsAttempt($second, 'j', '2026-11-12 14:31:00', cellsCompleted('2026-11-12 14:33:00'));
    // At the very moment the afternoon's count runs up to: after it.
    cellsView($first, 'm', '2026-11-12 14:27:00');
    cellsAttempt($first, 'm', '2026-11-12 14:27:00');
    cellsAttempt($second, 'e', '2026-11-12 14:00:00', cellsCompleted('2026-11-12 14:27:00'));
    cellsAttempt($first, 'k', '2026-11-12 14:36:00', ['user_id' => $bob->id, 'account_outcome' => GameAccountOutcome::SignedIn, 'account_outcome_at' => '2026-11-12 14:36:10']);
    cellsAttempt($first, null, '2026-11-12 14:36:30');
    cellsAttempt($second, $ann, '2026-11-12 14:00:00');

    $windows = fn (CarbonImmutable $now): array => [
        ['2026-11-01 00:00:00', $now->addSecond()->toDateTimeString()],
        ['2026-11-09 00:00:00', '2026-11-11 00:00:00'],
        ['2026-11-09 06:00:00', '2026-11-12 13:00:00'],
        ['2026-11-12 00:00:00', $now->addSecond()->toDateTimeString()],
        ['2026-11-11 00:00:00', $now->subDay()->addSecond()->toDateTimeString()],
        ['1970-01-01 00:00:00', $now->addSecond()->toDateTimeString()],
        ['2026-11-11 00:00:00', GameCellDays::momentOf($now)->toDateTimeString()],
        ['2026-11-12 00:00:00', GameCellDays::momentOf($now)->subMinute()->toDateTimeString()],
        ['2026-11-09 23:59:59', '2026-11-12 00:00:00'],
    ];

    // Yesterday still open, then just closed and settled, then the afternoon, then days later.
    foreach (['2026-11-12 00:40:00', '2026-11-12 01:10:00', '2026-11-12 14:37:00', '2026-11-15 09:00:00'] as $now) {
        $this->travelTo($now);
        countGameCells();
        compareGameCells($first, $windows(CarbonImmutable::now('UTC')));
    }

    $funnel = cellsFunnels(cellsScope($first, '2026-11-01 00:00:00', '2026-11-12 14:37:01'))[0];

    expect(gameCellsThrough())->toBe(app(AnalyticsRollupGate::class)->cellsKept() ? '2026-11-14' : null)
        ->and([$funnel->visitors, $funnel->started, $funnel->completed, $funnel->starters, $funnel->finishers, $funnel->goalReached, $funnel->goalPeople, $funnel->registered, $funnel->replayers])
        ->toBe([7, 19, 8, 10, 4, 2, 2, 3, 5])
        ->and([$funnel->signedIn, $funnel->shared, $funnel->sharers, $funnel->signUpStarters, $funnel->wentToFeed, $funnel->votedInFeed, $funnel->wentToPost])
        ->toBe([2, 4, 4, 1, 1, 1, 1]);

    // A day marked as changed is read from the events and attempts: a cell behind its back is never read.
    AnalyticsGameCell::query()->where('day', '2026-11-09')->update(['attempts' => 100]);
    app(MarkAnalyticsGameCellsAction::class)->handle(CarbonImmutable::parse('2026-11-09 12:00:00'), whenever: true);

    compareGameCells($first, $windows(CarbonImmutable::now('UTC')));

    if (app(AnalyticsRollupGate::class)->cellsKept()) {
        expect(readGameCells(fn () => cellsFunnels(cellsScope($first, '2026-11-01 00:00:00', '2026-11-12 14:37:01'), 'version')))->toBeTrue();
    }
});

it('reads the cells, never every view and attempt, while they are kept, and the events and attempts otherwise', function () {
    $this->travelTo('2026-11-10 10:00:00');
    $version = cellsGame();
    cellsView($version, 'ann', '2026-11-10 10:00:00');
    cellsAttempt($version, 'ann', '2026-11-10 10:01:00');
    $this->travelTo('2026-11-12 12:00:00');
    countGameCells();
    $scope = cellsScope($version, '2026-11-01 00:00:00', '2026-11-12 12:00:01');
    $read = fn (?string $by = null, ?GameMetricsScope $of = null): bool => readGameCells(fn () => app(GameMetricsQuery::class)->funnels($of ?? $scope, $by));
    $kept = app(AnalyticsRollupGate::class)->cellsKept();

    expect($read())->toBe($kept)
        ->and($read('flow'))->toBe($kept)
        // A device or a country is the recorded visits', which the cells don't keep.
        ->and($read('device'))->toBeFalse()
        ->and($read(of: cellsScope($version, '2026-11-01 00:00:00', '2026-11-12 12:00:01', visits: new VisitFilter(TrafficDevice::Mobile))))->toBeFalse()
        // Within a day that is over no cell fits: the events and attempts are read whole.
        ->and($read(of: cellsScope($version, '2026-11-10 06:00:00', '2026-11-10 18:00:00')))->toBeFalse();

    // The open days counted longer ago than the rows may be behind: a count that keeps failing.
    config(['analytics.rollup.stale_minutes' => 30]);
    $this->travelTo('2026-11-12 12:20:00');
    app()->forgetScopedInstances();

    expect($read())->toBe($kept);

    $this->travelTo('2026-11-12 12:20:01');
    app()->forgetScopedInstances();

    expect($read())->toBeFalse();

    countGameCells();

    expect($read())->toBe($kept);

    config(['analytics.rollup.game_cells' => false]);
    app()->forgetScopedInstances();

    expect($read())->toBeFalse();

    config(['analytics.rollup.game_cells' => true]);
    // Before the cells are filled, and while the cells of each day's people aren't read.
    AnalyticsRollupState::query()->update(['game_cells_filled_at' => null]);
    app()->forgetScopedInstances();

    expect($read())->toBeFalse();

    countGameCells();
    config(['analytics.rollup.cells' => false]);
    app()->forgetScopedInstances();

    expect($read())->toBeFalse();
});

it('reads the events and attempts while more days wait to be counted again than a refresh leaves', function () {
    $this->travelTo('2026-11-10 10:00:00');
    $version = cellsGame();
    cellsAttempt($version, 'ann', '2026-11-10 10:01:00');
    $this->travelTo('2026-12-30 12:00:00');
    countGameCells();
    $scope = cellsScope($version, '2026-11-01 00:00:00', '2026-12-30 12:00:01');
    $mark = fn (int $days) => AnalyticsDirtyGameDay::query()->create(['day' => CarbonImmutable::parse('2026-11-12')->addDays($days)->toDateString(), 'token' => (string) Str::ulid(), 'marked_at' => now()]);

    foreach (range(1, AnalyticsRollupGate::MOST_CHANGED_DAYS) as $days) {
        $mark($days);
    }

    app()->forgetScopedInstances();

    expect(readGameCells(fn () => app(GameMetricsQuery::class)->funnels($scope)))->toBe(app(AnalyticsRollupGate::class)->cellsKept());

    $mark(AnalyticsRollupGate::MOST_CHANGED_DAYS + 1);
    app()->forgetScopedInstances();

    expect(readGameCells(fn () => app(GameMetricsQuery::class)->funnels($scope)))->toBeFalse()
        ->and(cellsFunnels($scope)[0]->started)->toBe(1);
});

it('counts the days from the first view’s or attempt’s, passing over days with none, up to the last closed and settled, then the days since up to a moment', function () {
    $this->travelTo('2026-11-10 08:00:00');
    $version = cellsGame();

    // Nothing played yet: every day up to the last that can be counted whole is, with nothing in it.
    expect(countGameCells())->toBe(app(AnalyticsRollupGate::class)->cellsKept() ? 1 : 0);

    if (! app(AnalyticsRollupGate::class)->cellsKept()) {
        expect(gameCellsThrough())->toBeNull()
            ->and(AnalyticsGameCell::query()->count())->toBe(0);

        return;
    }

    expect(gameCellsThrough())->toBe('2026-11-09')
        ->and(AnalyticsGameCell::query()->count())->toBe(0);

    cellsView($version, 'ann', '2026-11-10 08:00:00');
    cellsAttempt($version, 'bob', '2026-11-10 09:00:00');
    $this->travelTo('2026-11-13 00:30:00');
    cellsAttempt($version, 'cat', '2026-11-12 23:00:00');
    cellsAttempt($version, 'cat', '2026-11-13 00:10:00');

    // The 10th counted whole; the 11th with nothing; the 12th still open, and today, up to ten minutes ago.
    expect(countGameCells())->toBe(2)
        ->and(gameCellsThrough())->toBe('2026-11-11')
        ->and(AnalyticsGameCell::query()->orderBy('day')->get()->map(fn (AnalyticsGameCell $cell): array => [$cell->day, $cell->moment?->toDateTimeString(), $cell->attempts])->all())
        ->toBe([['2026-11-10', null, 1], ['2026-11-12', '2026-11-13 00:20:00', 1], ['2026-11-13', '2026-11-13 00:20:00', 1]])
        ->and(AnalyticsRollupState::current()->game_cells_filled_at?->toDateTimeString())->toBe('2026-11-10 08:00:00');

    // Within five minutes nothing new is counted; then the open days up to a newer moment, the count before kept.
    $this->travelTo('2026-11-13 00:34:00');

    expect(countGameCells())->toBe(0);

    $this->travelTo('2026-11-13 00:35:00');

    expect(countGameCells())->toBe(1)
        ->and(AnalyticsGameCell::query()->whereNotNull('moment')->distinct()->orderBy('moment')->pluck('moment')->map(fn (mixed $moment): string => CarbonImmutable::parse($moment)->toDateTimeString())->all())
        ->toBe(['2026-11-13 00:20:00', '2026-11-13 00:25:00']);

    // The 12th closed and settled at 01:10: counted whole, and the counts up to a moment more than ten minutes before the
    // newest go, but the one just before it.
    $this->travelTo('2026-11-13 01:10:00');

    expect(countGameCells())->toBe(2)
        ->and(gameCellsThrough())->toBe('2026-11-12')
        ->and(AnalyticsGameCell::query()->whereNull('moment')->orderBy('day')->pluck('day')->all())->toBe(['2026-11-10', '2026-11-12'])
        ->and(AnalyticsGameCell::query()->whereNotNull('moment')->distinct()->orderBy('moment')->pluck('moment')->map(fn (mixed $moment): string => CarbonImmutable::parse($moment)->toDateTimeString())->all())
        ->toBe(['2026-11-13 00:25:00', '2026-11-13 01:00:00']);

    compareGameCells($version, [['2026-11-01 00:00:00', '2026-11-13 01:10:01']]);
});

it('fills the days of what was played before it, the earliest first, a run at a time', function () {
    if (! app(AnalyticsRollupGate::class)->cellsKept()) {
        expect(countGameCells())->toBe(0);

        return;
    }

    $version = cellsGame();
    cellsAttempt($version, 'ann', '2026-10-01 10:00:00');
    cellsView($version, 'bob', '2026-10-03 10:00:00');
    $this->travelTo('2026-10-05 12:00:00');

    // A run with no time left counts nothing.
    expect(app(RefreshAnalyticsGameCellsAction::class)->handle(microtime(true) - 1))->toBe(0)
        ->and(gameCellsThrough())->toBeNull()
        ->and(countGameCells())->toBe(3)
        ->and(gameCellsThrough())->toBe('2026-10-04')
        ->and(AnalyticsGameCell::query()->whereNull('moment')->orderBy('day')->pluck('day')->all())->toBe(['2026-10-01', '2026-10-03']);

    compareGameCells($version, [['2026-09-20 00:00:00', '2026-10-05 12:00:01']]);
});

it('counts the last days again at night, a check that nothing was missed', function () {
    $this->travelTo('2026-11-10 10:00:00');
    $version = cellsGame();
    cellsAttempt($version, 'ann', '2026-11-10 10:00:00');
    $this->travelTo('2026-11-12 12:00:00');
    countGameCells();
    // A play no writer told of.
    cellsAttempt($version, 'bob', '2026-11-10 11:00:00');

    expect(AnalyticsGameCell::query()->where('day', '2026-11-10')->value('attempts'))->toBe(app(AnalyticsRollupGate::class)->cellsKept() ? 1 : null);

    countGameCells(recheckDays: 3);

    expect(AnalyticsGameCell::query()->where('day', '2026-11-10')->value('attempts'))->toBe(app(AnalyticsRollupGate::class)->cellsKept() ? 2 : null);
});

it('is counted by analytics:refresh-visits before the visits', function () {
    $this->travelTo('2026-11-10 10:00:00');
    $version = cellsGame();
    cellsAttempt($version, 'ann', '2026-11-10 10:00:00');
    $this->travelTo('2026-11-12 12:00:00');

    $this->artisan('analytics:refresh-visits')->assertSuccessful();

    expect(gameCellsThrough())->toBe(app(AnalyticsRollupGate::class)->cellsKept() ? '2026-11-11' : null);
});

it('gives a browser one number for good, the next to a browser new, and lets one go only once no cell holds it, never the highest', function () {
    if (! app(AnalyticsRollupGate::class)->cellsKept()) {
        expect(app(RefreshAnalyticsGameCellsAction::class)->forgetNumbers())->toBe(0);

        return;
    }

    $this->travelTo('2025-09-01 10:00:00');
    $version = cellsGame();
    cellsView($version, 'ann', '2025-09-01 10:00:00');
    cellsView($version, 'bob', '2025-09-01 11:00:00');
    cellsAttempt($version, 'bob', '2025-09-01 11:01:00');
    $this->travelTo('2025-09-03 12:00:00');
    countGameCells();
    cellsView($version, 'cat', '2025-09-05 10:00:00');
    cellsView($version, 'ann', '2025-09-05 11:00:00');
    $this->travelTo('2025-09-07 12:00:00');
    countGameCells();
    countGameCells(recheckDays: 10);
    $numbers = fn (): array => AnalyticsGamePid::query()->orderBy('pid')->get()->mapWithKeys(fn (AnalyticsGamePid $number): array => [$number->visitor_hash => $number->pid])->all();
    [$ann, $bob, $cat] = [hash('sha256', 'ann'), hash('sha256', 'bob'), hash('sha256', 'cat')];
    $given = $numbers();

    expect($given)->toHaveCount(3)
        ->and(array_values($given))->toBe([1, 2, 3])
        ->and($given[$cat])->toBe(3);

    // A year on, the views of the first days purged: bob's play keeps his number; ann's goes once her days are counted
    // again without her, and cat's, the highest, stays.
    $this->travelTo('2026-10-10 12:00:00');
    $this->artisan('games:purge-expired')->assertSuccessful();

    expect($numbers())->toBe($given)
        ->and(markedGameDays())->toBe(['2025-09-01', '2025-09-05']);

    countGameCells();
    $this->artisan('games:purge-expired')->assertSuccessful();

    expect($numbers())->toBe([$bob => $given[$bob], $cat => $given[$cat]]);

    // Back again: a number of her own, after every one given.
    cellsView($version, 'ann', '2026-10-10 11:00:00');
    $this->travelTo('2026-10-12 12:00:00');
    countGameCells();

    expect($numbers()[$ann])->toBe(4);

    compareGameCells($version, [['2025-08-01 00:00:00', '2026-10-12 12:00:01']]);
});

it('reads a view and a play begun today at once, and a play finished on the day it began, telling nothing', function () {
    $this->travelTo('2026-11-12 09:00:00');
    $version = cellsGame();
    $game = $version->game;
    $this->travelTo('2026-11-12 12:00:00');
    countGameCells();

    app(AssignGameVersionAction::class)->handle($game, cellsGuest('ann'), viewed: true);
    $attempt = app(StartGameAttemptAction::class)->handle($game, cellsGuest('ann'), 'en');
    $this->travelTo('2026-11-12 18:00:00');
    countGameCells();
    $this->travelTo('2026-11-12 18:01:00');
    app(RecordGameAnswerAction::class)->handle($attempt, cellsGuest('ann'), 0, 0);
    $funnel = cellsFunnels(cellsScope($version, '2026-11-01 00:00:00', '2026-11-12 18:01:01'))[0];

    expect(markedGameDays())->toBe([])
        ->and([$funnel->visitors, $funnel->starters, $funnel->finishers, $funnel->completed])->toBe([1, 1, 1, 1]);
});

it('counts an attempt completed once the day it began on closed, the day marked until it is counted again', function () {
    $this->travelTo('2026-11-10 10:15:00');
    $version = cellsGame();
    $attempt = app(StartGameAttemptAction::class)->handle($version->game, cellsGuest('ann'), 'en');
    $this->travelTo('2026-11-12 12:00:00');
    countGameCells();
    $scope = cellsScope($version, '2026-11-01 00:00:00', '2026-11-12 12:00:01');

    app(RecordGameAnswerAction::class)->handle($attempt, cellsGuest('ann'), 0, 0);

    expect(markedGameDays())->toBe(app(AnalyticsRollupGate::class)->cellsKept() ? ['2026-11-10'] : [])
        ->and(cellsFunnels($scope)[0]->finishers)->toBe(1);

    countGameCells();

    expect(markedGameDays())->toBe([])
        ->and(cellsFunnels($scope)[0]->finishers)->toBe(1);
});

it('counts an account confirmed days after the play, and the goal it reaches, the day of the play marked', function () {
    $this->travelTo('2026-11-10 10:15:00');
    // A flow that asks for an account after the result: an account is its goal.
    $version = cellsGame([GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Registration], ['full_result' => ['signup' => 'step']]);
    $attempt = app(StartGameAttemptAction::class)->handle($version->game, cellsGuest('ann'), 'en');
    $member = User::factory()->create();
    AccountAttribution::factory()->create(['user_id' => $member->id, 'credited_attempt_id' => $attempt->id, 'signed_up_at' => '2026-11-10 10:20:00']);
    $this->travelTo('2026-11-12 12:00:00');
    countGameCells();
    $scope = cellsScope($version, '2026-11-01 00:00:00', '2026-11-12 12:00:01');

    expect($version->flowGoal())->toBe(GameFlowGoal::Account)
        ->and(cellsFunnels($scope)[0]->registered)->toBe(0);

    app(ConfirmAccountAttributionAction::class)->handle($member->id, CarbonImmutable::now());
    $funnel = cellsFunnels($scope)[0];

    expect(markedGameDays())->toBe(app(AnalyticsRollupGate::class)->cellsKept() ? ['2026-11-10'] : [])
        ->and([$funnel->registered, $funnel->goalReached, $funnel->goalPeople])->toBe([1, 1, 1]);

    countGameCells();

    expect(markedGameDays())->toBe([]);

    cellsFunnels($scope);
});

it('counts an account confirmed on the day of the play, while the day is open, from the moment it was confirmed', function () {
    $this->travelTo('2026-11-12 08:00:00');
    $version = cellsGame([GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Registration], ['full_result' => ['signup' => 'step']]);
    $attempt = app(StartGameAttemptAction::class)->handle($version->game, cellsGuest('ann'), 'en');
    $member = User::factory()->create();
    AccountAttribution::factory()->create(['user_id' => $member->id, 'credited_attempt_id' => $attempt->id, 'signed_up_at' => '2026-11-12 08:20:00']);
    $this->travelTo('2026-11-12 12:00:00');
    countGameCells();

    app(ConfirmAccountAttributionAction::class)->handle($member->id, CarbonImmutable::now());
    $funnel = cellsFunnels(cellsScope($version, '2026-11-01 00:00:00', '2026-11-12 12:00:01'))[0];

    expect(markedGameDays())->toBe([])
        ->and([$funnel->registered, $funnel->goalReached, $funnel->goalPeople])->toBe([1, 1, 1]);
});

it('counts the account that took a guest’s play once its day closed, the play a guest’s still, and an account’s plays when it is deleted', function () {
    $this->travelTo('2026-11-10 23:30:00');
    $version = cellsGame();
    $attempt = app(StartGameAttemptAction::class)->handle($version->game, cellsGuest('ann'), 'en');
    $member = User::factory()->create();
    // Signed in past the day's close, within the hours a guest's play is taken in.
    $this->travelTo('2026-11-11 03:00:00');
    countGameCells();
    $scope = fn (GameAudience $audience): GameMetricsScope => cellsScope($version, '2026-11-01 00:00:00', '2026-11-11 03:00:01', $audience);

    expect(app(ClaimGuestGameAttemptsAction::class)->handle($member, [$attempt->public_id], GameAccountOutcome::SignedIn))->toBe(1)
        ->and(markedGameDays())->toBe(app(AnalyticsRollupGate::class)->cellsKept() ? ['2026-11-10'] : [])
        ->and(cellsFunnels($scope(GameAudience::Anonymous))[0]->signedIn)->toBe(1)
        ->and(cellsFunnels($scope(GameAudience::Anonymous))[0]->starters)->toBe(1)
        ->and(cellsFunnels($scope(GameAudience::Authenticated))[0]->starters)->toBe(0);

    countGameCells();
    app(AnonymizeUserAccountAction::class)->execute($member);

    expect(markedGameDays())->toBe([])
        ->and(GameAttempt::query()->whereKey($attempt->id)->exists())->toBeTrue()
        ->and(cellsFunnels($scope(GameAudience::Anonymous))[0]->signedIn)->toBe(1)
        ->and(cellsFunnels($scope(GameAudience::Anonymous))[0]->starters)->toBe(1);
});

it('counts a share, a sign-up begun and a way on recorded once the day of the play closed, the day marked', function () {
    $this->travelTo('2026-11-10 10:15:00');
    $version = cellsGame();
    $attempt = app(StartGameAttemptAction::class)->handle($version->game, cellsGuest('ann'), 'en');
    $this->travelTo('2026-11-12 12:00:00');
    countGameCells();
    $scope = cellsScope($version, '2026-11-01 00:00:00', '2026-11-12 12:00:01');

    app(GameEventRecorder::class)->record(GameEventType::ShareClicked, (int) $attempt->game_id, cellsGuest('ann'), attempt: $attempt);
    app(GameEventRecorder::class)->record(GameEventType::RegistrationCtaClicked, (int) $attempt->game_id, cellsGuest('ann'), attempt: $attempt, detail: ['provider' => 'email']);
    // Sent on to the feed, as LeaveGameAction writes it: the way on, then its exit.
    GameAttempt::query()->whereKey($attempt->id)->update(['onward_kind' => GameOnwardKind::Feed, 'onward_at' => now()]);
    app(GameEventRecorder::class)->record(GameEventType::Exit, (int) $attempt->game_id, cellsGuest('ann'), attempt: $attempt->fresh(), detail: ['step_type' => GameOnwardKind::Feed->value]);
    $funnel = cellsFunnels($scope)[0];

    expect(markedGameDays())->toBe(app(AnalyticsRollupGate::class)->cellsKept() ? ['2026-11-10'] : [])
        ->and([$funnel->shared, $funnel->sharers, $funnel->signUpStarters, $funnel->wentToFeed])->toBe([1, 1, 1, 1]);

    countGameCells();

    expect(markedGameDays())->toBe([]);

    cellsFunnels($scope);

    // A view, or a step seen, tells nothing.
    app(GameEventRecorder::class)->record(GameEventType::StepViewed, (int) $attempt->game_id, cellsGuest('ann'), attempt: $attempt);

    expect(markedGameDays())->toBe([]);
});

it('counts the plays and views of an unconfirmed account removed with everything it did, their days marked', function () {
    $this->travelTo('2026-11-01 10:15:00');
    $version = cellsGame();
    $game = $version->game;
    $bot = User::factory()->unverified()->create(['created_at' => now()]);
    $player = new GameVisitor(GameAudience::Authenticated, (int) $bot->id, null, hash('sha256', 'bot'));
    // Its own view and play, signed in, and a guest's play it took on signing up.
    app(AssignGameVersionAction::class)->handle($game, $player, viewed: true);
    app(StartGameAttemptAction::class)->handle($game, $player, 'en');
    $guest = app(StartGameAttemptAction::class)->handle($game, cellsGuest('ann'), 'en');
    app(ClaimGuestGameAttemptsAction::class)->handle($bot, [$guest->public_id], GameAccountOutcome::Registered);
    $this->travelTo('2026-11-03 09:00:00');
    app(StartGameAttemptAction::class)->handle($game, cellsGuest('bob'), 'en');
    $this->travelTo('2026-11-12 12:00:00');
    countGameCells();
    $scope = fn (GameAudience $audience): GameMetricsScope => cellsScope($version, '2026-11-01 00:00:00', '2026-11-12 12:00:01', $audience);

    expect(cellsFunnels($scope(GameAudience::Authenticated))[0]->visitors)->toBe(1)
        ->and(app(PurgeUnconfirmedAccountAction::class)->handle((int) $bot->id, UnconfirmedAccountsQuery::cutoff()))->toBe(UnconfirmedAccountPurgeOutcome::Purged)
        ->and(markedGameDays())->toBe(app(AnalyticsRollupGate::class)->cellsKept() ? ['2026-11-01'] : [])
        ->and(cellsFunnels($scope(GameAudience::Anonymous))[0]->starters)->toBe(1)
        ->and(cellsFunnels($scope(GameAudience::Authenticated))[0]->visitors)->toBe(0)
        ->and(cellsFunnels($scope(GameAudience::Authenticated))[0]->started)->toBe(0);

    countGameCells();

    expect(markedGameDays())->toBe([]);

    compareGameCells($version, [['2026-11-01 00:00:00', '2026-11-12 12:00:01']]);
});

it('finds a guest’s play of an open day finished since its moment by when it finished, once an account purged takes the event that told of it', function () {
    $this->travelTo('2026-10-01 10:00:00');
    $version = cellsGame();
    $bot = User::factory()->unverified()->create(['created_at' => now()]);
    $attempt = cellsAttempt($version, 'ann', '2026-11-12 10:00:00');
    $this->travelTo('2026-11-12 14:37:00');
    countGameCells();
    // Finished after the moment the day is counted up to, by a visitor signed in to the account on a guest's play it never took.
    GameAttempt::query()->whereKey($attempt->id)->update(cellsCompleted('2026-11-12 14:38:00'));
    (new GameEvent)->forceFill([
        'game_id' => $attempt->game_id,
        'version_id' => $attempt->version_id,
        'attempt_id' => $attempt->id,
        'audience' => GameAudience::Anonymous,
        'user_id' => $bot->id,
        'visitor_hash' => $attempt->visitor_hash,
        'type' => GameEventType::Completed,
        'occurred_at' => '2026-11-12 14:38:00',
    ])->save();
    $scope = cellsScope($version, '2026-11-01 00:00:00', '2026-11-12 14:40:01');

    expect(cellsFunnels($scope)[0]->finishers)->toBe(1);

    $this->travelTo('2026-11-12 14:40:00');

    expect(app(PurgeUnconfirmedAccountAction::class)->handle((int) $bot->id, now()))->toBe(UnconfirmedAccountPurgeOutcome::Purged)
        ->and(GameAttempt::query()->whereKey($attempt->id)->exists())->toBeTrue()
        ->and(GameEvent::query()->where('attempt_id', $attempt->id)->exists())->toBeFalse()
        ->and([cellsFunnels($scope)[0]->completed, cellsFunnels($scope)[0]->finishers])->toBe([1, 1]);

    // Counted again up to a moment still before it finished: found the same way.
    $this->travelTo('2026-11-12 14:45:00');
    countGameCells();
    $scope = cellsScope($version, '2026-11-01 00:00:00', '2026-11-12 14:45:01');

    expect([cellsFunnels($scope)[0]->completed, cellsFunnels($scope)[0]->finishers])->toBe([1, 1]);
});

it('keeps the count a read just learned while a refresh that fell behind counts the open days up to a newer moment', function () {
    $this->travelTo('2026-11-12 08:00:00');
    $version = cellsGame();
    cellsView($version, 'ann', '2026-11-12 09:00:00');
    cellsAttempt($version, 'ann', '2026-11-12 09:01:00');
    $this->travelTo('2026-11-12 12:00:00');
    countGameCells();
    // A quarter of an hour without a count, less than the open days may be behind before a game's figures stop reading them.
    $this->travelTo('2026-11-12 12:15:00');
    $scope = cellsScope($version, '2026-11-01 00:00:00', '2026-11-12 12:15:01');
    config(['analytics.rollup.game_cells' => false]);
    app()->forgetScopedInstances();
    $live = app(GameMetricsQuery::class)->funnels($scope);
    config(['analytics.rollup.game_cells' => true]);
    app()->forgetScopedInstances();
    // The refresh runs just as the read has learned the moment, before it reads the cells.
    $raced = false;
    DB::listen(function ($query) use (&$raced): void {
        if (! $raced && str_contains($query->sql, 'analytics_dirty_game_days') && str_contains($query->sql, 'game_cells_moment')) {
            $raced = true;
            app(RefreshAnalyticsGameCellsAction::class)->handle(microtime(true) + 60);
        }
    });

    expect(app(GameMetricsQuery::class)->funnels($scope))->toEqual($live)
        ->and($raced)->toBe(app(AnalyticsRollupGate::class)->cellsKept())
        ->and($live[0]->visitors)->toBe(1);

    if (app(AnalyticsRollupGate::class)->cellsKept()) {
        // Kept until a newer count replaces the one after it, and ten minutes are past.
        $moments = fn (): array => AnalyticsGameCell::query()->whereNotNull('moment')->distinct()->orderBy('moment')->pluck('moment')->map(fn (CarbonImmutable $moment): string => $moment->toDateTimeString())->all();

        expect($moments())->toBe(['2026-11-12 11:50:00', '2026-11-12 12:05:00']);

        $this->travelTo('2026-11-12 12:21:00');
        countGameCells();

        expect($moments())->toBe(['2026-11-12 12:05:00', '2026-11-12 12:11:00']);
    }
});

it('counts a game nobody saw, and one seen with no version but never played, the same both ways', function () {
    $this->travelTo('2026-11-01 08:00:00');
    $empty = cellsGame();
    $seen = cellsGame();
    cellsView($seen, 'ann', '2026-11-05 09:00:00', ofVersion: false);
    cellsView($seen, null, '2026-11-05 09:00:00');
    $this->travelTo('2026-11-12 12:00:00');
    countGameCells();
    $windows = [['2026-11-01 00:00:00', '2026-11-12 12:00:01'], ['2026-11-04 00:00:00', '2026-11-06 00:00:00']];

    compareGameCells($empty, $windows);
    compareGameCells($seen, $windows);

    expect(cellsVisitors(cellsScope($seen, '2026-11-01 00:00:00', '2026-11-12 12:00:01')))->toBe(1)
        ->and(cellsFunnels(cellsScope($empty, '2026-11-01 00:00:00', '2026-11-12 12:00:01'))[0]->visitors)->toBe(0);
});

it('counts the views purged with their retention, their days marked, the plays staying', function () {
    $this->travelTo('2025-10-01 10:15:00');
    $version = cellsGame();
    app(AssignGameVersionAction::class)->handle($version->game, cellsGuest('ann'), viewed: true);
    app(StartGameAttemptAction::class)->handle($version->game, cellsGuest('ann'), 'en');
    $this->travelTo('2026-11-12 12:00:00');
    countGameCells();
    $scope = cellsScope($version, '1970-01-01 00:00:00', '2026-11-12 12:00:01');

    expect(cellsFunnels($scope)[0]->visitors)->toBe(1);

    $this->artisan('games:purge-expired')->assertSuccessful();
    $funnel = cellsFunnels($scope)[0];

    expect(markedGameDays())->toBe(app(AnalyticsRollupGate::class)->cellsKept() ? ['2025-10-01'] : [])
        ->and([$funnel->visitors, $funnel->starters])->toBe([0, 1]);

    countGameCells();

    expect(markedGameDays())->toBe([]);

    cellsFunnels($scope);
});

it('marks a day only once it closed, a deletion whatever the day, each with a new token, keeping when it was first marked', function () {
    $mark = app(MarkAnalyticsGameCellsAction::class);
    $kept = app(AnalyticsRollupGate::class)->cellsKept();
    $now = CarbonImmutable::parse('2026-11-12 00:59:59', 'UTC');

    $mark->handle(CarbonImmutable::parse('2026-11-11 23:59:59'), now: $now);
    $mark->handle(CarbonImmutable::parse('2026-11-12 00:30:00'), now: $now);

    expect(markedGameDays())->toBe([]);

    $mark->handle(CarbonImmutable::parse('2026-11-11 23:59:59'), now: $now->addSecond());
    $first = AnalyticsDirtyGameDay::query()->first();
    $mark->handle(CarbonImmutable::parse('2026-11-11 08:00:00'), now: $now->addMinutes(5));
    $mark->removing(['2026-11-12 00:10:00', CarbonImmutable::parse('2026-11-11 10:00:00'), null]);

    expect(markedGameDays())->toBe($kept ? ['2026-11-11', '2026-11-12'] : []);

    if ($kept) {
        expect(AnalyticsDirtyGameDay::query()->find('2026-11-11')->token)->not->toBe($first->token)
            ->and(AnalyticsDirtyGameDay::query()->find('2026-11-11')->marked_at->toDateTimeString())->toBe('2026-11-12 01:00:00');
    }
});
