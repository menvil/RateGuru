<?php

namespace App\Queries\Analytics;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Enums\GameAccountOutcome;
use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameOnwardKind;
use App\Models\AccountAttribution;
use App\Models\AnalyticsGameCell;
use App\Models\AnalyticsGamePid;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Queries\Games\GameMetricsQuery;
use App\Support\Analytics\GameCellDays;
use App\Support\Analytics\GameCellWindow;
use App\Support\Games\Analytics\GameMetricsScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * The people of a game's days (analytics_game_cells, docs/architecture/analytics.md,
 * "The people of a game's days"): what RefreshAnalyticsGameCellsAction writes —
 * the guests' browsers of a stretch with no number yet, each with the next
 * one; and a UTC day's cells of every game, audience and version, whole or up
 * to a moment, each person counted by the very key GameMetricsQuery tells
 * people apart by, a guest's browser by its number and a player by their
 * account's id — and what GameMetricsQuery reads of them in their place: a
 * window's visitors, and its attempts, the people behind them and what their
 * attempts went on to, as the size of the union of the cells' sets beside
 * the events and attempts of every stretch the cells don't cover — a person
 * read live counted only when no cell holds them — and the sums summed. The
 * people who played again are a fold of the cells in turn: each cell's people
 * with two plays or more, and those it shares with the cells before it
 * (rb_or_agg over the cells before, as a window). And the numbers no cell
 * holds any more, which the purge lets go. PostgreSQL with the roaringbitmap
 * extension only: callers ask the gate first.
 *
 * What an attempt's events tell of it is read from the events from the
 * moment its stretch began on, by the attempt: each is of the attempt's own
 * game and never earlier than its start (GameEventRecorder), the very events
 * GameMetricsQuery reads of the window.
 */
final class AnalyticsGameCellsQuery implements RawSqlPersistenceBoundary
{
    /**
     * The people funnels() counts by an event of their attempts, and the
     * event: a type, and for a way on the step it went to, the attempt's own
     * way on (its `onward_kind`) — as GameMetricsQuery::funnels() reads them.
     *
     * @var array<string, array{0: GameEventType, 1: GameOnwardKind|null}>
     */
    private const array EVENT_PEOPLE = [
        'sharers' => [GameEventType::ShareClicked, null],
        'sign_up_starters' => [GameEventType::RegistrationCtaClicked, null],
        'went_to_post' => [GameEventType::PostOpened, null],
        'voted_on_post' => [GameEventType::PostVoted, null],
        'rated_post' => [GameEventType::PostRated, null],
        'went_to_feed' => [GameEventType::Exit, GameOnwardKind::Feed],
        'voted_in_feed' => [GameEventType::FeedVoted, null],
        'went_to_page' => [GameEventType::Exit, GameOnwardKind::Page],
        'went_to_next_game' => [GameEventType::Exit, GameOnwardKind::NextGame],
    ];

    /** @var array<string, string> the people of a cell's attempts but the accounts that signed in, each as the test of a person's attempts summed up (`%s` the table) */
    private const array PLAY_PEOPLE = [
        'starters' => '%s.attempts > 0',
        'finishers' => '%s.finished = 1',
        'goal_people' => '%s.reached = 1',
        'repeaters' => '%s.attempts > 1',
        'sharers' => '%s.sharers = 1',
        'sign_up_starters' => '%s.sign_up_starters = 1',
        'went_to_post' => '%s.went_to_post = 1',
        'voted_on_post' => '%s.voted_on_post = 1',
        'rated_post' => '%s.rated_post = 1',
        'went_to_feed' => '%s.went_to_feed = 1',
        'voted_in_feed' => '%s.voted_in_feed = 1',
        'went_to_page' => '%s.went_to_page = 1',
        'went_to_next_game' => '%s.went_to_next_game = 1',
    ];

    /** @var list<string> each sum of a cell's attempts: started, completed, the goal reached, with a confirmed account credited, shared, and with nobody to tell them apart by */
    public const array SUMS = ['attempts', 'completed', 'goal_reached', 'registered', 'shared', 'unnamed'];

    /**
     * The events recorded with every change the cells count of an attempt
     * after it began — completed, its goal reached, taken by an account, an
     * account credited to it confirmed, and what funnels() counts its events
     * by: what tells a day of its attempts that changed (MarkAnalyticsGameCellsAction),
     * and what finds the open days' attempts changed since their moment.
     *
     * @var list<GameEventType>
     */
    public const array TOLD_BY = [
        GameEventType::Completed,
        GameEventType::GoalReached,
        GameEventType::AccountAttributed,
        GameEventType::AccountConfirmed,
        ...GameMetricsQuery::FUNNEL_EVENTS,
    ];

    /** What the stretches read live are ordered by among the days: after every one. */
    private const string LIVE_DAY = '9999-12-31';

    private const string NOBODY = 'rb_build(ARRAY[]::integer[])';

    /** @return list<string> every set of people a cell keeps: its visitors, the people of its attempts, and the accounts its attempts signed in */
    public static function people(): array
    {
        return ['viewers', ...array_keys(self::PLAY_PEOPLE), 'signed_in'];
    }

    /** @return list<string> the columns of a cell, in the order day() selects them */
    public static function columns(): array
    {
        return ['game_id', 'day', 'audience', 'version_id', 'moment', ...self::people(), ...self::SUMS];
    }

    /** The first UTC day from the one given on — of every day, without one — with a game's view or attempt; none when there is none. */
    public function firstDayFrom(?string $day): ?string
    {
        $from = $day === null ? null : CarbonImmutable::parse($day, 'UTC');
        $firsts = array_filter([
            GameEvent::query()->where('type', GameEventType::View->value)->when($from !== null, fn (Builder $query): Builder => $query->where('occurred_at', '>=', $from))->min('occurred_at'),
            GameAttempt::query()->when($from !== null, fn (Builder $query): Builder => $query->where('started_at', '>=', $from))->min('started_at'),
        ], fn (mixed $first): bool => $first !== null);

        return $firsts === [] ? null : min(array_map(fn (mixed $first): string => GameCellDays::dayOf(CarbonImmutable::parse((string) $first, 'UTC')), $firsts));
    }

    /**
     * The guests' browsers seen in the stretch — a view, an attempt — with no
     * number yet, in their order, each with the next number: one writer at a
     * time (the refresh's lock), so the highest number given is the one to
     * count on from.
     *
     * @return Builder<AnalyticsGamePid> visitor_hash, pid
     */
    public function freshNumbers(CarbonImmutable $from, CarbonImmutable $before): Builder
    {
        $views = GameEvent::query()
            ->where('type', GameEventType::View->value)
            ->where('audience', GameAudience::Anonymous->value)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $before)
            ->whereNotNull('visitor_hash')
            ->select('visitor_hash');
        $plays = GameAttempt::query()
            ->where('audience', GameAudience::Anonymous->value)
            ->where('started_at', '>=', $from)
            ->where('started_at', '<', $before)
            ->whereNotNull('visitor_hash')
            ->select('visitor_hash');
        $fresh = AnalyticsGamePid::query()
            ->fromSub($views->union($plays), 'seen')
            ->whereNotExists(fn ($numbered) => $numbered->from('analytics_game_pids as numbered')->whereColumn('numbered.visitor_hash', 'seen.visitor_hash'))
            ->select('seen.visitor_hash');

        return AnalyticsGamePid::query()
            ->fromSub($fresh, 'fresh')
            ->selectRaw('fresh.visitor_hash, ? + ROW_NUMBER() OVER (ORDER BY fresh.visitor_hash)', [(int) AnalyticsGamePid::query()->max('pid')]);
    }

    /**
     * A UTC day's cells of every game, audience and version (columns()), one
     * row a cell with a view or an attempt: whole, or — for a day still open —
     * up to a moment, its views and attempts begun before it and of those
     * attempts what came of them before it. Every browser of the day has its
     * number by then (freshNumbers()); an attempt nobody can be told apart by
     * is `unnamed`.
     *
     * @return Builder<AnalyticsGameCell>
     */
    public function day(string $day, ?CarbonImmutable $moment = null): Builder
    {
        $from = CarbonImmutable::parse($day, 'UTC');
        $before = $moment !== null && $moment->lessThan($from->addDay()) ? $moment : $from->addDay();
        $plays = array_keys(self::PLAY_PEOPLE);
        [$pid, $pidBindings] = self::number('e');
        $views = $this->numbered(GameEvent::query()->from('game_events as e'), 'e')
            ->where('e.type', GameEventType::View->value)
            ->where('e.occurred_at', '>=', $from)
            ->where('e.occurred_at', '<', $before)
            ->select('e.game_id', 'e.audience', 'e.version_id')
            ->selectRaw("{$pid} AS pid", $pidBindings);
        $viewers = GameEvent::query()
            ->fromSub($views, 'v')
            ->groupBy('v.game_id', 'v.audience', 'v.version_id')
            ->select('v.game_id', 'v.audience', 'v.version_id')
            ->selectRaw(implode(', ', [
                'rb_build_agg(v.pid) FILTER (WHERE v.pid IS NOT NULL) AS viewers',
                ...array_map(fn (string $set): string => "NULL::roaringbitmap AS {$set}", [...$plays, 'signed_in']),
                ...array_map(fn (string $sum): string => "0 AS {$sum}", self::SUMS),
            ]), []);

        [$pid, $pidBindings] = self::number('a');
        [$flags, $flagBindings] = self::flags($moment === null ? 'whole' : 'until', $moment);
        $attempts = $this->numbered(GameAttempt::query()->from('game_attempts as a'), 'a')
            ->leftJoinSub(self::events(null, $from, $moment), 'ev', 'ev.attempt_id', '=', 'a.id')
            ->where('a.started_at', '>=', $from)
            ->where('a.started_at', '<', $before)
            ->select('a.game_id', 'a.audience', 'a.version_id')
            ->selectRaw("{$pid} AS pid, {$flags}", [...$pidBindings, ...$flagBindings]);
        // Each person's attempts summed up first — every attempt nobody can be told apart by together — then each cell's people.
        $people = GameAttempt::query()
            ->fromSub($attempts, 'p')
            ->groupBy('p.game_id', 'p.audience', 'p.version_id', 'p.pid')
            ->select('p.game_id', 'p.audience', 'p.version_id', 'p.pid')
            ->selectRaw(self::summedUp(), []);
        $starters = GameAttempt::query()
            ->fromSub($people, 'people')
            ->groupBy('people.game_id', 'people.audience', 'people.version_id')
            ->select('people.game_id', 'people.audience', 'people.version_id')
            ->selectRaw(implode(', ', [
                'NULL::roaringbitmap AS viewers',
                ...array_map(fn (string $set): string => 'rb_build_agg(people.pid) FILTER (WHERE people.pid IS NOT NULL AND '.sprintf(self::PLAY_PEOPLE[$set], 'people').") AS {$set}", $plays),
                'rb_or_agg(people.signed_in) AS signed_in',
                ...array_map(fn (string $sum): string => "SUM(people.{$sum}) AS {$sum}", array_diff(self::SUMS, ['unnamed'])),
                'COALESCE(SUM(people.attempts) FILTER (WHERE people.pid IS NULL), 0) AS unnamed',
            ]), []);

        return AnalyticsGameCell::query()
            ->fromSub($viewers->unionAll($starters), 'parts')
            ->groupBy('parts.game_id', 'parts.audience', 'parts.version_id')
            ->selectRaw(
                'parts.game_id, ? AS day, parts.audience, parts.version_id, CAST(? AS timestamp(0)) AS moment, '.implode(', ', [
                    ...array_map(fn (string $set): string => "rb_or_agg(parts.{$set})::bytea AS {$set}", self::people()),
                    ...array_map(fn (string $sum): string => "SUM(parts.{$sum}) AS {$sum}", self::SUMS),
                ]),
                [$day, $moment?->toDateTimeString()],
            );
    }

    /**
     * A window's visitors (GameMetricsQuery::funnels()'s `visitors`), in all
     * or by version, flow or content set (`bucket`): the people of the cells
     * it reads beside those of the views of every stretch read live, each
     * person once — a guest seen live whose browser has no number yet is in
     * no cell, so counted on their own.
     *
     * @param  string|null  $by  null, or one of GameMetricsQuery::GROUPS
     * @return Builder<AnalyticsGameCell>
     */
    public function visitors(GameMetricsScope $scope, ?string $by, GameCellWindow $window): Builder
    {
        $bucket = $by === null ? null : GameMetricsQuery::GROUPS[$by];
        $parts = $this->cells($scope, $bucket, $window)->selectRaw('c.viewers::roaringbitmap AS people, 0 AS fresh', []);

        if ($window->live !== []) {
            $views = self::within($this->person(GameEvent::query()->from('game_events as e'), 'e', $scope, $bucket), 'e.occurred_at', $window->live)
                ->where('e.game_id', $scope->gameId)
                ->where('e.type', GameEventType::View->value)
                ->where('e.audience', $scope->audience->value)
                ->when($scope->versionId !== null, fn (Builder $query): Builder => $query->where('e.version_id', $scope->versionId));
            $people = GameEvent::query()
                ->fromSub($views, 'v')
                ->whereNotNull('v.person')
                ->when($bucket !== null, fn (Builder $query): Builder => $query->groupBy('v.bucket')->select('v.bucket'))
                ->groupBy('v.person')
                ->addSelect('v.person')
                ->selectRaw(self::pidOf('v.person', $scope).' AS pid', []);
            $parts->unionAll(GameEvent::query()
                ->fromSub($people, 'people')
                ->when($bucket !== null, fn (Builder $query): Builder => $query->groupBy('people.bucket')->select('people.bucket'))
                ->selectRaw('rb_build_agg(people.pid) FILTER (WHERE people.pid IS NOT NULL) AS people, COUNT(*) FILTER (WHERE people.pid IS NULL) AS fresh', []));
        }

        return AnalyticsGameCell::query()
            ->fromSub($parts, 'parts')
            ->when($bucket !== null, fn (Builder $query): Builder => $query->groupBy('parts.bucket')->select('parts.bucket'))
            ->selectRaw('COALESCE(rb_cardinality(rb_or_agg(parts.people)), 0) + COALESCE(SUM(parts.fresh), 0) AS visitors', []);
    }

    /**
     * A window's attempts, the people behind them and what they went on to —
     * what GameMetricsQuery::funnels() reads of its attempts and their events
     * but the emails and the posts' sign-ins: started, completed, starters,
     * finishers, registered, goal_reached, goal_people, replayers, signed_in,
     * shared and the people of each event (EVENT_PEOPLE) — in all or by
     * version, flow or content set (`bucket`). The cells it reads, then the
     * attempts begun in every stretch read live and — of those the open days'
     * cells count, begun before their moment — those that changed since,
     * found by the event recorded with each change, the account confirmed
     * since, or the moment it was completed, reached its goal or was taken by
     * an account, which stays when the event goes: all summed up per person
     * first and taken as one more cell, the last. The people who played again
     * are those with two attempts or more in a cell and those a cell shares
     * with the cells before it, and the attempts nobody can be told apart by
     * as one more when there are several of them, as grouping them by person
     * puts them together.
     *
     * @param  string|null  $by  null, or one of GameMetricsQuery::GROUPS
     * @return Builder<AnalyticsGameCell>
     */
    public function attempts(GameMetricsScope $scope, ?string $by, GameCellWindow $window): Builder
    {
        $bucket = $by === null ? null : GameMetricsQuery::GROUPS[$by];
        $plays = array_keys(self::PLAY_PEOPLE);
        $parts = $this->cells($scope, $bucket, $window)->selectRaw(implode(', ', [
            'c.day AS day', 'c.version_id AS version_id',
            ...array_map(fn (string $set): string => "c.{$set}::roaringbitmap AS {$set}", [...$plays, 'signed_in']),
            ...array_map(fn (string $sum): string => "c.{$sum} AS {$sum}", self::SUMS),
            ...array_map(fn (string $set): string => "0 AS fresh_{$set}", $plays),
        ]), []);
        $rows = [];

        if ($window->live !== []) {
            [$flags, $bindings] = self::flags('whole', null);
            $rows[] = self::within($this->plays($scope, $bucket), 'a.started_at', $window->live)
                ->leftJoinSub(self::events($scope->gameId, $window->live[0][0]), 'ev', 'ev.attempt_id', '=', 'a.id')
                ->selectRaw($flags, $bindings);
        }

        if ($window->late !== [] && $window->moment !== null) {
            $moment = $window->moment;
            [$flags, $bindings] = self::flags('since', $moment);
            $changed = GameEvent::query()
                ->where('game_id', $scope->gameId)
                ->whereIn('type', array_map(fn (GameEventType $type): string => $type->value, self::TOLD_BY))
                ->where('occurred_at', '>=', $moment)
                ->whereNotNull('attempt_id')
                ->select('attempt_id')
                ->union(AccountAttribution::query()->where('confirmed_at', '>=', $moment)->whereNotNull('credited_attempt_id')->select('credited_attempt_id'));
            // By its own moments as well as by its events: the attempt keeps them, while the event that told
            // of the change can go — an account purged takes those it recorded on a guest's play it never took.
            $rows[] = self::within($this->plays($scope, $bucket), 'a.started_at', $window->late)
                ->where(fn (Builder $since): Builder => $since
                    ->whereIn('a.id', $changed)
                    ->orWhere('a.completed_at', '>=', $moment)
                    ->orWhere('a.goal_reached_at', '>=', $moment)
                    ->orWhere('a.account_outcome_at', '>=', $moment))
                ->leftJoinSub(self::events($scope->gameId, $window->late[0][0], sharedSince: $moment), 'ev', 'ev.attempt_id', '=', 'a.id')
                ->selectRaw($flags, $bindings);
        }

        if ($rows !== []) {
            $played = array_shift($rows);

            foreach ($rows as $more) {
                $played->unionAll($more);
            }

            $people = GameAttempt::query()
                ->fromSub($played, 'p')
                ->when($bucket !== null, fn (Builder $query): Builder => $query->groupBy('p.bucket')->select('p.bucket'))
                ->groupBy('p.person')
                ->addSelect('p.person')
                ->selectRaw(self::pidOf('p.person', $scope).' AS pid, '.self::summedUp(), []);
            $fresh = 'people.person IS NOT NULL AND people.pid IS NULL AND ';
            $parts->unionAll(GameAttempt::query()
                ->fromSub($people, 'people')
                ->when($bucket !== null, fn (Builder $query): Builder => $query->groupBy('people.bucket')->select('people.bucket'))
                ->selectRaw(implode(', ', [
                    'CAST(? AS char(10)) AS day', 'CAST(NULL AS bigint) AS version_id',
                    ...array_map(fn (string $set): string => 'rb_build_agg(people.pid) FILTER (WHERE people.pid IS NOT NULL AND '.sprintf(self::PLAY_PEOPLE[$set], 'people').") AS {$set}", $plays),
                    'rb_or_agg(people.signed_in) AS signed_in',
                    ...array_map(fn (string $sum): string => "SUM(people.{$sum}) AS {$sum}", array_diff(self::SUMS, ['unnamed'])),
                    'COALESCE(SUM(people.attempts) FILTER (WHERE people.person IS NULL), 0) AS unnamed',
                    ...array_map(fn (string $set): string => "COUNT(*) FILTER (WHERE {$fresh}".sprintf(self::PLAY_PEOPLE[$set], 'people').") AS fresh_{$set}", $plays),
                ]), [self::LIVE_DAY]));
        }

        $partition = $bucket !== null ? 'PARTITION BY parts.bucket ' : '';
        // Each cell beside the people of every cell before it, in turn: who it shares with them started again.
        $folded = AnalyticsGameCell::query()
            ->fromSub($parts, 'parts')
            ->select('parts.*')
            ->selectRaw("rb_or_agg(parts.starters) OVER ({$partition}ORDER BY parts.day, parts.version_id ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING) AS before", []);
        $count = fn (string $set): string => "COALESCE(rb_cardinality(rb_or_agg(folded.{$set})), 0) + SUM(folded.fresh_{$set}) AS {$set}";

        return AnalyticsGameCell::query()
            ->fromSub($folded, 'folded')
            ->when($bucket !== null, fn (Builder $query): Builder => $query->groupBy('folded.bucket')->select('folded.bucket'))
            ->selectRaw(implode(', ', [
                'SUM(folded.attempts) AS started',
                'SUM(folded.completed) AS completed',
                $count('starters'),
                $count('finishers'),
                'SUM(folded.registered) AS registered',
                'SUM(folded.goal_reached) AS goal_reached',
                $count('goal_people'),
                'rb_cardinality(rb_or(COALESCE(rb_or_agg(folded.repeaters), '.self::NOBODY.'), COALESCE(rb_or_agg(rb_and(folded.starters, folded.before)), '.self::NOBODY.')))'
                    .' + SUM(folded.fresh_repeaters) + CASE WHEN SUM(folded.unnamed) > ? THEN 1 ELSE 0 END AS replayers',
                'COALESCE(rb_cardinality(rb_or_agg(folded.signed_in)), 0) AS signed_in',
                'SUM(folded.shared) AS shared',
                ...array_map($count, array_keys(self::EVENT_PEOPLE)),
            ]), [1]);
    }

    /**
     * The guests' numbers no cell holds any more — never the highest, which
     * the next number counts on from: a number given again would make two
     * browsers one person. Every cell of the guests is read, whatever its day
     * or moment; every person of an attempt is among those who started, so
     * the visitors and the starters are enough.
     *
     * @return Builder<AnalyticsGamePid>
     */
    public function unheldNumbers(): Builder
    {
        $people = fn (string $column): Builder => AnalyticsGameCell::query()
            ->where('audience', GameAudience::Anonymous->value)
            ->whereNotNull($column)
            ->selectRaw("{$column}::roaringbitmap AS people", []);
        $held = AnalyticsGameCell::query()
            ->fromSub($people('viewers')->unionAll($people('starters')), 'held')
            ->selectRaw('COALESCE(rb_or_agg(held.people), '.self::NOBODY.')', []);

        return AnalyticsGamePid::query()
            ->where('pid', '<', (int) AnalyticsGamePid::query()->max('pid'))
            ->whereRaw('NOT rb_contains(('.$held->toSql().'), analytics_game_pids.pid)', $held->getBindings());
    }

    /**
     * What each attempt (`a`, its events' flags beside it as `ev`: events())
     * counts for, as SQL after its person, with its bindings: `started`,
     * `finished`, `reached`, `registered`, `shared`, `signed` (the account it
     * signed in, if so) and a flag per person of an event (EVENT_PEOPLE) — as
     * it stands (`whole`); as it stood before a moment, for an open day's
     * cells (`until`), its events read up to the moment; or, of an attempt
     * that changed since the moment, what it counts for now that the cells
     * as of the moment don't (`since`): the sums only for what came since,
     * the people as they stand — a person a cell already holds counted once
     * all the same.
     *
     * @param  'whole'|'until'|'since'  $as
     * @return array{0: string, 1: list<mixed>}
     */
    private static function flags(string $as, ?CarbonImmutable $moment): array
    {
        $completed = GameAttemptStatus::Completed->value;
        $signedIn = GameAccountOutcome::SignedIn->value;
        $columns = [];
        $bindings = [];
        $add = function (string $sql, array $values) use (&$columns, &$bindings): void {
            $columns[] = $sql;
            array_push($bindings, ...$values);
        };
        [$confirmed, $confirmedBindings] = self::confirmed($as === 'until' ? ['<', $moment] : null);

        if ($as === 'since') {
            [$confirmedSince, $confirmedSinceBindings] = self::confirmed(['>=', $moment]);
            [$confirmedBefore, $confirmedBeforeBindings] = self::confirmed(['<', $moment]);
            $add('0 AS started', []);
            $add('CASE WHEN a.status = ? AND a.completed_at >= ? THEN 1 ELSE 0 END AS finished', [$completed, $moment]);
            $add('CASE WHEN a.goal_reached_at >= ? THEN 1 ELSE 0 END AS reached', [$moment]);
            $add("CASE WHEN {$confirmedSince} AND NOT {$confirmedBefore} THEN 1 ELSE 0 END AS registered", [...$confirmedSinceBindings, ...$confirmedBeforeBindings]);
            $add('CASE WHEN ev.shared_since = 1 AND ev.shared_before = 0 THEN 1 ELSE 0 END AS shared', []);
            $add('CASE WHEN a.account_outcome = ? THEN CAST(a.user_id AS integer) END AS signed', [$signedIn]);
        } elseif ($as === 'until') {
            // Completed before the moment; one completed with no moment kept, whenever.
            $add('1 AS started', []);
            $add('CASE WHEN a.status = ? AND (a.completed_at IS NULL OR a.completed_at < ?) THEN 1 ELSE 0 END AS finished', [$completed, $moment]);
            $add('CASE WHEN a.goal_reached_at < ? THEN 1 ELSE 0 END AS reached', [$moment]);
            $add("CASE WHEN {$confirmed} THEN 1 ELSE 0 END AS registered", $confirmedBindings);
            $add('COALESCE(ev.sharers, 0) AS shared', []);
            $add('CASE WHEN a.account_outcome = ? AND (a.account_outcome_at IS NULL OR a.account_outcome_at < ?) THEN CAST(a.user_id AS integer) END AS signed', [$signedIn, $moment]);
        } else {
            $add('1 AS started', []);
            $add('CASE WHEN a.status = ? THEN 1 ELSE 0 END AS finished', [$completed]);
            $add('CASE WHEN a.goal_reached_at IS NOT NULL THEN 1 ELSE 0 END AS reached', []);
            $add("CASE WHEN {$confirmed} THEN 1 ELSE 0 END AS registered", $confirmedBindings);
            $add('COALESCE(ev.sharers, 0) AS shared', []);
            $add('CASE WHEN a.account_outcome = ? THEN CAST(a.user_id AS integer) END AS signed', [$signedIn]);
        }

        foreach (self::EVENT_PEOPLE as $people => [, $way]) {
            $add($way === null ? "COALESCE(ev.{$people}, 0) AS {$people}" : "CASE WHEN ev.{$people} = 1 AND a.onward_kind = ? THEN 1 ELSE 0 END AS {$people}", $way === null ? [] : [$way->value]);
        }

        return [implode(', ', $columns), $bindings];
    }

    /**
     * The events funnels() counts people by (EVENT_PEOPLE), as flags per
     * attempt (`attempt_id`), for flags() to read as `ev`: of those recorded
     * from a moment on — no attempt's events are earlier than it began — of
     * one game when given, and before a moment when given; beside them,
     * whether the attempt was shared before and since a moment, when one is
     * given for that.
     *
     * @return Builder<GameEvent>
     */
    private static function events(?int $gameId, CarbonImmutable $since, ?CarbonImmutable $until = null, ?CarbonImmutable $sharedSince = null): Builder
    {
        $flags = [];
        $bindings = [];

        foreach (self::EVENT_PEOPLE as $people => [$type, $way]) {
            $flags[] = 'MAX(CASE WHEN f.type = ?'.($way === null ? '' : ' AND f.step_type = ?')." THEN 1 ELSE 0 END) AS {$people}";
            array_push($bindings, ...($way === null ? [$type->value] : [$type->value, $way->value]));
        }

        if ($sharedSince !== null) {
            $flags[] = 'MAX(CASE WHEN f.type = ? AND f.occurred_at >= ? THEN 1 ELSE 0 END) AS shared_since';
            $flags[] = 'MAX(CASE WHEN f.type = ? AND f.occurred_at < ? THEN 1 ELSE 0 END) AS shared_before';
            array_push($bindings, GameEventType::ShareClicked->value, $sharedSince, GameEventType::ShareClicked->value, $sharedSince);
        }

        return GameEvent::query()
            ->from('game_events as f')
            ->when($gameId !== null, fn (Builder $query): Builder => $query->where('f.game_id', $gameId))
            ->whereIn('f.type', array_map(fn (GameEventType $type): string => $type->value, GameMetricsQuery::FUNNEL_EVENTS))
            ->where('f.occurred_at', '>=', $since)
            ->when($until !== null, fn (Builder $query): Builder => $query->where('f.occurred_at', '<', $until))
            ->whereNotNull('f.attempt_id')
            ->groupBy('f.attempt_id')
            ->select('f.attempt_id')
            ->selectRaw(implode(', ', $flags), $bindings);
    }

    /** A person's attempts summed up (flags()), as SQL over them (`p`). */
    private static function summedUp(): string
    {
        return implode(', ', [
            'SUM(p.started) AS attempts',
            'MAX(p.finished) AS finished',
            'SUM(p.finished) AS completed',
            'MAX(p.reached) AS reached',
            'SUM(p.reached) AS goal_reached',
            'SUM(p.registered) AS registered',
            'SUM(p.shared) AS shared',
            'rb_build_agg(p.signed) FILTER (WHERE p.signed IS NOT NULL) AS signed_in',
            ...array_map(fn (string $people): string => "MAX(p.{$people}) AS {$people}", array_keys(self::EVENT_PEOPLE)),
        ]);
    }

    /**
     * The cells of the scope a window reads — the days counted whole, and the
     * open days as counted up to the moment, but those changed since — with
     * their `bucket` when split.
     *
     * @return Builder<AnalyticsGameCell>
     */
    private function cells(GameMetricsScope $scope, ?string $bucket, GameCellWindow $window): Builder
    {
        $cells = AnalyticsGameCell::query()
            ->from('analytics_game_cells as c')
            ->where('c.game_id', $scope->gameId)
            ->where('c.audience', $scope->audience->value)
            ->when($scope->versionId !== null, fn (Builder $query): Builder => $query->where('c.version_id', $scope->versionId))
            ->where(function (Builder $days) use ($window): void {
                if ($window->closedFrom !== null) {
                    $days->orWhere(fn (Builder $closed): Builder => $closed->whereNull('c.moment')->where('c.day', '>=', $window->closedFrom)->where('c.day', '<', $window->closedBefore));
                }

                if ($window->openFrom !== null) {
                    $days->orWhere(fn (Builder $open): Builder => $open->where('c.moment', $window->moment)->where('c.day', '>=', $window->openFrom)->where('c.day', '<=', $window->openThrough));
                }

                if (! $window->readsCells()) {
                    $days->whereIn('c.id', []);
                }
            })
            ->when($window->except !== [], fn (Builder $query): Builder => $query->whereNotIn('c.day', $window->except));

        return $bucket === null ? $cells : $cells->join('game_versions', 'game_versions.id', '=', 'c.version_id')->select("{$bucket} as bucket");
    }

    /**
     * The scope's attempts with their person and number, and their `bucket`
     * when split.
     *
     * @return Builder<GameAttempt>
     */
    private function plays(GameMetricsScope $scope, ?string $bucket): Builder
    {
        return $this->person(GameAttempt::query()->from('game_attempts as a'), 'a', $scope, $bucket)
            ->where('a.game_id', $scope->gameId)
            ->where('a.audience', $scope->audience->value)
            ->when($scope->versionId !== null, fn (Builder $query): Builder => $query->where('a.version_id', $scope->versionId));
    }

    /**
     * Rows of events or attempts with their `bucket` when split, and the
     * person each is of as GameMetricsQuery tells them apart: a guest by the
     * browser's hash, a player by their account.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function person(Builder $query, string $alias, GameMetricsScope $scope, ?string $bucket): Builder
    {
        if ($bucket !== null) {
            $query->join('game_versions', 'game_versions.id', '=', "{$alias}.version_id")->addSelect("{$bucket} as bucket");
        }

        return $query->addSelect($alias.'.'.($scope->audience === GameAudience::Anonymous ? 'visitor_hash' : 'user_id').' as person');
    }

    /**
     * The number a cell holds a person of the scope's audience by, as SQL
     * over their key: a guest's browser's when it has one yet — looked up
     * once a person, by the browser's own row, whatever the numbers there
     * are — a player's account's id.
     */
    private static function pidOf(string $person, GameMetricsScope $scope): string
    {
        return $scope->audience === GameAudience::Anonymous
            ? "(SELECT numbers.pid FROM analytics_game_pids AS numbers WHERE numbers.visitor_hash = {$person})"
            : "CAST({$person} AS integer)";
    }

    /**
     * Rows of events or attempts of every audience with the number of a
     * guest's browser beside them (`numbers.pid`, joined on the guests' rows
     * only).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function numbered(Builder $query, string $alias): Builder
    {
        return $query->leftJoin('analytics_game_pids as numbers', fn (JoinClause $join): JoinClause => $join
            ->on('numbers.visitor_hash', '=', "{$alias}.visitor_hash")
            ->where("{$alias}.audience", '=', GameAudience::Anonymous->value));
    }

    /**
     * The number a row of any audience counts its person by, as SQL with its
     * binding: a guest's browser's (numbered()), a player's account's id;
     * none for a row nobody can be told apart by.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function number(string $alias): array
    {
        return ["CASE WHEN {$alias}.audience = ? THEN numbers.pid ELSE CAST({$alias}.user_id AS integer) END", [GameAudience::Anonymous->value]];
    }

    /**
     * Whether an attempt (`a`) has a confirmed account credited to it — of
     * those confirmed before or since a moment, when one is given — as SQL
     * with its bindings: what withConfirmedAccounts() finds of it.
     *
     * @param  array{0: string, 1: CarbonImmutable|null}|null  $when  the comparison with the moment and the moment
     * @return array{0: string, 1: list<mixed>}
     */
    private static function confirmed(?array $when): array
    {
        $sql = 'EXISTS (SELECT 1 FROM account_attributions AS credited WHERE credited.credited_attempt_id = a.id AND credited.confirmed_at IS NOT NULL';

        return $when === null ? [$sql.')', []] : ["{$sql} AND credited.confirmed_at {$when[0]} ?)", [$when[1]]];
    }

    /**
     * The rows whose moment falls in one of the stretches given.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $stretches
     * @return Builder<TModel>
     */
    private static function within(Builder $query, string $column, array $stretches): Builder
    {
        return $query->where(function (Builder $within) use ($column, $stretches): void {
            foreach ($stretches as [$from, $before]) {
                $within->orWhere(fn (Builder $stretch): Builder => $stretch->where($column, '>=', $from)->where($column, '<', $before));
            }
        });
    }
}
