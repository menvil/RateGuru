<?php

namespace App\Queries\Games;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Enums\GameAccountOutcome;
use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Models\GameAnswer;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Support\Games\Analytics\GameFunnel;
use App\Support\Games\Analytics\GameMetricsScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Reads a game's figures back from what playing it recorded: what Growth →
 * Games → Analytics, the library and Traffic show.
 *
 *  - funnels()      visitors, starts, completions, emails, sign-ups begun,
 *                   accounts, shares and repeat players — attempts and the
 *                   people behind them — in all, or per version, flow or set;
 *  - signUpMethods() the people who began signing up, by how;
 *  - reach()        how many started attempts answered each round of a version;
 *  - results()      the completed attempts of a version by result;
 *  - periods()      the attempts started and completed in each traffic period;
 *  - library()      per game, the starts, completions, anonymous visitors and
 *                   new accounts of a window.
 *
 * Visitors come from `view` events — anonymous visitors told apart by their
 * cookie's hash, players by their account. Everything else counts the
 * attempts started in the window. The aggregates are conditional counts and
 * distinct counts Eloquent has no expression for: the raw SQL is this class's
 * own, the same on PostgreSQL, MariaDB and SQLite, and every value in it is
 * bound. Read-only.
 */
final class GameMetricsQuery implements RawSqlPersistenceBoundary
{
    /** What funnels() can split its figures by, and the version column each reads. */
    public const GROUPS = [
        'version' => 'game_versions.id',
        'flow' => 'game_versions.flow_id',
        'set' => 'game_versions.content_set_id',
    ];

    /**
     * The funnel of the scope, in all (keyed 0) or split by version, flow or
     * content set (keyed by its id). A person who saw two versions is one
     * visitor in all, and one in each version's row.
     *
     * @return array<int, GameFunnel>
     */
    public function funnels(GameMetricsScope $scope, ?string $by = null): array
    {
        if ($by !== null && ! array_key_exists($by, self::GROUPS)) {
            throw new InvalidArgumentException("[{$by}] is not something a game's figures are split by.");
        }

        $visitors = $this->counts(
            $this->grouped($this->viewEvents($scope), 'game_events.version_id', $by)
                ->selectRaw(
                    'COUNT(DISTINCT CASE WHEN game_events.audience = ? THEN game_events.visitor_hash END)
                    + COUNT(DISTINCT CASE WHEN game_events.audience = ? THEN game_events.user_id END) AS visitors',
                    [GameAudience::Anonymous->value, GameAudience::Authenticated->value],
                ),
        );
        $person = self::person($scope);
        $attempts = $this->counts(
            $this->grouped($this->attempts($scope), 'game_attempts.version_id', $by)
                ->selectRaw(
                    "COUNT(*) AS started,
                    SUM(CASE WHEN game_attempts.status = ? THEN 1 ELSE 0 END) AS completed,
                    COUNT(DISTINCT {$person}) AS starters,
                    COUNT(DISTINCT CASE WHEN game_attempts.status = ? THEN {$person} END) AS finishers,
                    COUNT(DISTINCT CASE WHEN game_attempts.account_outcome = ? THEN game_attempts.user_id END) AS registered,
                    COUNT(DISTINCT CASE WHEN game_attempts.account_outcome = ? THEN game_attempts.user_id END) AS signed_in",
                    [GameAttemptStatus::Completed->value, GameAttemptStatus::Completed->value, GameAccountOutcome::Registered->value, GameAccountOutcome::SignedIn->value],
                ),
        );
        $emails = $this->counts(
            $this->grouped($this->cohortJoin(GameEmailCapture::query(), 'game_email_captures.attempt_id', $scope), 'game_attempts.version_id', $by)
                ->selectRaw("COUNT(*) AS emails, COUNT(DISTINCT {$person}) AS emailers", []),
        );
        $shared = $this->counts(
            $this->grouped(
                $this->cohortJoin(GameEvent::query(), 'game_events.attempt_id', $scope)->where('game_events.type', GameEventType::ShareClicked->value),
                'game_attempts.version_id',
                $by,
            )->selectRaw("COUNT(DISTINCT game_events.attempt_id) AS shared, COUNT(DISTINCT {$person}) AS sharers", []),
        );
        $signUps = $this->counts(
            $this->grouped(
                $this->cohortJoin(GameEvent::query(), 'game_events.attempt_id', $scope)->where('game_events.type', GameEventType::RegistrationCtaClicked->value),
                'game_attempts.version_id',
                $by,
            )->selectRaw("COUNT(DISTINCT {$person}) AS sign_up_starters", []),
        );
        $replayers = $this->replayers($scope, $by);

        $buckets = array_unique([...array_keys($visitors), ...array_keys($attempts), ...array_keys($emails), ...array_keys($shared), ...array_keys($signUps), ...array_keys($replayers)]);
        sort($buckets);

        if ($by === null) {
            $buckets = [0];
        }

        $funnels = [];

        foreach ($buckets as $bucket) {
            $funnels[$bucket] = new GameFunnel(
                visitors: $visitors[$bucket]['visitors'] ?? 0,
                started: $attempts[$bucket]['started'] ?? 0,
                completed: $attempts[$bucket]['completed'] ?? 0,
                emails: $emails[$bucket]['emails'] ?? 0,
                registered: $attempts[$bucket]['registered'] ?? 0,
                signedIn: $attempts[$bucket]['signed_in'] ?? 0,
                shared: $shared[$bucket]['shared'] ?? 0,
                replayers: $replayers[$bucket] ?? 0,
                starters: $attempts[$bucket]['starters'] ?? 0,
                finishers: $attempts[$bucket]['finishers'] ?? 0,
                emailers: $emails[$bucket]['emailers'] ?? 0,
                sharers: $shared[$bucket]['sharers'] ?? 0,
                signUpStarters: $signUps[$bucket]['sign_up_starters'] ?? 0,
            );
        }

        return $funnels;
    }

    /**
     * How many of the version's attempts started in the scope answered each
     * round, by round index; a round nobody reached is absent.
     *
     * @return array<int, int>
     */
    public function reach(GameMetricsScope $scope, int $versionId): array
    {
        $rows = $this->cohortJoin(GameAnswer::query(), 'game_answers.attempt_id', $scope)
            ->where('game_attempts.version_id', $versionId)
            ->groupBy('game_answers.round_index')
            ->select('game_answers.round_index')
            ->selectRaw('COUNT(*) AS answered', [])
            ->toBase()
            ->get();

        $reach = [];

        foreach ($rows as $row) {
            $reach[(int) $row->round_index] = (int) $row->answered;
        }

        ksort($reach);

        return $reach;
    }

    /**
     * The version's attempts started in the scope and completed, by the key
     * of the result each earned.
     *
     * @return array<string, int>
     */
    public function results(GameMetricsScope $scope, int $versionId): array
    {
        $rows = $this->attempts($scope)
            ->where('game_attempts.version_id', $versionId)
            ->where('game_attempts.status', GameAttemptStatus::Completed->value)
            ->whereNotNull('game_attempts.profile_key')
            ->groupBy('game_attempts.profile_key')
            ->select('game_attempts.profile_key')
            ->selectRaw('COUNT(*) AS completed', [])
            ->toBase()
            ->get();

        $results = [];

        foreach ($rows as $row) {
            $results[(string) $row->profile_key] = (int) $row->completed;
        }

        return $results;
    }

    /**
     * Per traffic period of the game, the attempts started from an assignment
     * made in it and how many of those were completed. Periods nobody started
     * in are absent.
     *
     * @return array<int, array{started: int, completed: int}>
     */
    public function periods(int $gameId): array
    {
        $rows = GameAttempt::query()
            ->join('game_assignments', 'game_assignments.id', '=', 'game_attempts.assignment_id')
            ->where('game_attempts.game_id', $gameId)
            ->groupBy('game_assignments.period_id')
            ->select('game_assignments.period_id')
            ->selectRaw(
                'COUNT(*) AS started, SUM(CASE WHEN game_attempts.status = ? THEN 1 ELSE 0 END) AS completed',
                [GameAttemptStatus::Completed->value],
            )
            ->toBase()
            ->get();

        $periods = [];

        foreach ($rows as $row) {
            $periods[(int) $row->period_id] = ['started' => (int) $row->started, 'completed' => (int) $row->completed];
        }

        return $periods;
    }

    /**
     * Per game, over a window: the attempts started and completed, the
     * anonymous visitors, and the new accounts the attempts led to — the
     * library's columns. Games with nothing in the window are absent.
     *
     * @param  list<int>  $gameIds
     * @return array<int, array{started: int, completed: int, visitors: int, registered: int}>
     */
    public function library(array $gameIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($gameIds === []) {
            return [];
        }

        $attempts = GameAttempt::query()
            ->whereIn('game_attempts.game_id', $gameIds)
            ->where('game_attempts.started_at', '>=', $from)
            ->where('game_attempts.started_at', '<', $to)
            ->groupBy('game_attempts.game_id')
            ->select('game_attempts.game_id')
            ->selectRaw(
                'COUNT(*) AS started,
                SUM(CASE WHEN game_attempts.status = ? THEN 1 ELSE 0 END) AS completed,
                COUNT(DISTINCT CASE WHEN game_attempts.audience = ? AND game_attempts.account_outcome = ? THEN game_attempts.user_id END) AS registered',
                [GameAttemptStatus::Completed->value, GameAudience::Anonymous->value, GameAccountOutcome::Registered->value],
            )
            ->toBase()
            ->get()
            ->keyBy('game_id');
        $visitors = GameEvent::query()
            ->whereIn('game_events.game_id', $gameIds)
            ->where('game_events.type', GameEventType::View->value)
            ->where('game_events.audience', GameAudience::Anonymous->value)
            ->where('game_events.occurred_at', '>=', $from)
            ->where('game_events.occurred_at', '<', $to)
            ->groupBy('game_events.game_id')
            ->select('game_events.game_id')
            ->selectRaw('COUNT(DISTINCT game_events.visitor_hash) AS visitors', [])
            ->toBase()
            ->get()
            ->keyBy('game_id');

        $library = [];

        foreach ($gameIds as $gameId) {
            $row = $attempts->get($gameId);
            $seen = $visitors->get($gameId);

            if ($row === null && $seen === null) {
                continue;
            }

            $library[$gameId] = [
                'started' => (int) ($row->started ?? 0),
                'completed' => (int) ($row->completed ?? 0),
                'visitors' => (int) ($seen->visitors ?? 0),
                'registered' => (int) ($row->registered ?? 0),
            ];
        }

        return $library;
    }

    /**
     * The people of the scope who began signing up from a game, by how:
     * `email`, a provider, or the site's `register` or `login` dialog — the
     * detail a registration_cta_clicked event carries. A person who tried two
     * ways counts under each.
     *
     * @return array<string, int>
     */
    public function signUpMethods(GameMetricsScope $scope): array
    {
        $person = self::person($scope);
        $rows = $this->cohortJoin(GameEvent::query(), 'game_events.attempt_id', $scope)
            ->where('game_events.type', GameEventType::RegistrationCtaClicked->value)
            ->whereNotNull('game_events.provider')
            ->groupBy('game_events.provider')
            ->select('game_events.provider')
            ->selectRaw("COUNT(DISTINCT {$person}) AS people", [])
            ->orderBy('game_events.provider')
            ->toBase()
            ->get();
        $methods = [];

        foreach ($rows as $row) {
            $methods[(string) $row->provider] = (int) $row->people;
        }

        return $methods;
    }

    /** The column a person of the scope's audience is told apart by: an anonymous visitor's cookie hash, a player's account. */
    private static function person(GameMetricsScope $scope): string
    {
        return $scope->audience === GameAudience::Anonymous ? 'game_attempts.visitor_hash' : 'game_attempts.user_id';
    }

    /**
     * People who started more than one attempt in the scope, by group: each
     * person's attempts counted first, then those with more than one.
     *
     * @return array<int, int>
     */
    private function replayers(GameMetricsScope $scope, ?string $by): array
    {
        $identity = self::person($scope);
        $people = $this->grouped($this->attempts($scope), 'game_attempts.version_id', $by)
            ->addSelect("{$identity} as person")
            ->groupBy($identity)
            ->havingRaw('COUNT(*) > ?', [1]);

        $rows = GameAttempt::query()
            ->fromSub($people->toBase(), 'people')
            ->when($by !== null, fn (Builder $query) => $query->groupBy('people.bucket')->select('people.bucket'))
            ->selectRaw('COUNT(*) AS replayers', [])
            ->toBase()
            ->get();

        $replayers = [];

        foreach ($rows as $row) {
            $replayers[$by === null ? 0 : (int) $row->bucket] = (int) $row->replayers;
        }

        return $replayers;
    }

    /** @return Builder<GameEvent> the scope's `view` events */
    private function viewEvents(GameMetricsScope $scope): Builder
    {
        return GameEvent::query()
            ->where('game_events.game_id', $scope->gameId)
            ->where('game_events.type', GameEventType::View->value)
            ->where('game_events.audience', $scope->audience->value)
            ->where('game_events.occurred_at', '>=', $scope->from)
            ->where('game_events.occurred_at', '<', $scope->to)
            ->when($scope->versionId !== null, fn (Builder $query) => $query->where('game_events.version_id', $scope->versionId));
    }

    /** @return Builder<GameAttempt> the attempts started in the scope */
    private function attempts(GameMetricsScope $scope): Builder
    {
        return $this->cohort(GameAttempt::query(), $scope);
    }

    /**
     * Rows of another table that belong to an attempt started in the scope.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function cohortJoin(Builder $query, string $attemptColumn, GameMetricsScope $scope): Builder
    {
        return $this->cohort($query->join('game_attempts', 'game_attempts.id', '=', $attemptColumn), $scope);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function cohort(Builder $query, GameMetricsScope $scope): Builder
    {
        return $query
            ->where('game_attempts.game_id', $scope->gameId)
            ->where('game_attempts.audience', $scope->audience->value)
            ->where('game_attempts.started_at', '>=', $scope->from)
            ->where('game_attempts.started_at', '<', $scope->to)
            ->when($scope->versionId !== null, fn (Builder $inner) => $inner->where('game_attempts.version_id', $scope->versionId));
    }

    /**
     * The query split by version, flow or content set, read from the version
     * each row belongs to; unsplit without a group.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function grouped(Builder $query, string $versionColumn, ?string $by): Builder
    {
        if ($by === null) {
            return $query;
        }

        $column = self::GROUPS[$by];

        return $query->join('game_versions', 'game_versions.id', '=', $versionColumn)
            ->addSelect("{$column} as bucket")
            ->groupBy($column);
    }

    /**
     * The rows of a grouped aggregate by bucket (0 unsplit), every figure a
     * whole number.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<int, array<string, int>>
     */
    private function counts(Builder $query): array
    {
        $counts = [];

        foreach ($query->toBase()->get() as $row) {
            $values = (array) $row;
            $bucket = (int) ($values['bucket'] ?? 0);
            unset($values['bucket']);
            $counts[$bucket] = array_map(fn (mixed $value): int => (int) $value, $values);
        }

        return $counts;
    }
}
