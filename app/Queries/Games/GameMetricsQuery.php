<?php

namespace App\Queries\Games;

use App\Contracts\Persistence\RawSqlPersistenceBoundary;
use App\Enums\AccountSignupPlace;
use App\Enums\GameAccountOutcome;
use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameOnwardKind;
use App\Enums\TrafficDimension;
use App\Models\AccountAttribution;
use App\Models\GameAnswer;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Models\TrafficVisit;
use App\Support\Analytics\PeriodVersionFunnel;
use App\Support\Games\Analytics\GameFunnel;
use App\Support\Games\Analytics\GameMetricsScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use InvalidArgumentException;
use LogicException;

/**
 * Reads a game's figures back from what playing it recorded: what Growth →
 * Games → Analytics, the library and Traffic show.
 *
 *  - funnels()      visitors, starts, completions, emails, sign-ups begun,
 *                   accounts, shares, repeat players, and the people the
 *                   flow sent on to a post, the feed, a page or the next game
 *                   and what they did there — on a post, its sign-in form and
 *                   the accounts made there too — attempts and the people
 *                   behind them — in all, or per version, flow or set, or per
 *                   device, country or country group of their visits;
 *  - visitors()     the visitors alone;
 *  - finishers()    the people who finished one of some versions, each once;
 *  - sentToPost()   the people some versions sent on to a post, each once;
 *  - signUpMethods() the people who began signing up, by how;
 *  - reach()        how many started attempts answered each round of a version;
 *  - results()      the completed attempts of a version by result;
 *  - periods()      the attempts started and completed in each traffic period;
 *  - periodFunnel() per traffic period and version, how far the visitors it
 *                   assigned got, what the versions are compared by;
 *  - library()      per game, the starts, completions, anonymous visitors and
 *                   new accounts of a window.
 *
 * Visitors come from `view` events — anonymous visitors told apart by their
 * cookie's hash, players by their account. Everything else counts the
 * attempts started in the window. The game's own tables know no device or
 * country: those are the recorded visits'. With a device or country in the
 * scope, an attempt counts when the visit it came on (traffic_visit_id) is
 * one the filter admits, and a visitor when they have such a visit of the
 * game — a guest by the browser's hash, a player by a browser they signed up
 * or in on, or by the visit a play of theirs came on — so a play without a
 * recorded visit drops out. Visits are only those the tracker counts
 * (TrafficVisit::scopeTracked()), never a visit to the rest of the site. The aggregates are conditional counts and
 * distinct counts Eloquent has no expression for: the raw SQL is this class's
 * own, the same on PostgreSQL, MariaDB and SQLite but for a moment some
 * minutes on, which each engine writes its own way (minutesAfter()), every
 * value in it bound and every visit column named by TrafficDimension.
 * Read-only.
 */
final class GameMetricsQuery implements RawSqlPersistenceBoundary
{
    /** What funnels() can split its figures by from the version a row belongs to, and the version column each reads. */
    public const GROUPS = [
        'version' => 'game_versions.id',
        'flow' => 'game_versions.flow_id',
        'set' => 'game_versions.content_set_id',
    ];

    /**
     * What funnels() can split its figures by from the recorded visits
     * behind them — a play's own visit, a visitor's visits of the game — and
     * the dimension naming the visit column each reads; a country group is
     * worked out from the country.
     */
    public const VISIT_GROUPS = [
        'device' => TrafficDimension::Device,
        'country' => TrafficDimension::Country,
        'group' => TrafficDimension::Country,
    ];

    /** How long after a way on sent a visitor to a post what they do there is still the play's, as CreditGameSiteActivityAction credits it. */
    private const int ON_SITE_MINUTES = 30;

    /** The events of an attempt funnels() counts people by: shares, sign-ups begun, and the ways on and what came of them. */
    private const FUNNEL_EVENTS = [
        GameEventType::ShareClicked,
        GameEventType::RegistrationCtaClicked,
        GameEventType::PostOpened,
        GameEventType::PostVoted,
        GameEventType::PostRated,
        GameEventType::FeedVoted,
        GameEventType::Exit,
    ];

    /**
     * The funnel of the scope, in all (keyed 0) or split by version, flow or
     * content set (keyed by its id), or by the device, country or country
     * group of the recorded visits behind it (keyed by the device, the
     * country — '' where it is not known — or the group's index). A person
     * who saw two versions, or came from two countries, is one visitor in
     * all and one in each row; a play without a recorded visit is in no row
     * of a visit's. The ways on count the people a play's way on sent to a
     * post, the feed, a page or the next game (LeaveGameAction), and those
     * the votes after it were credited to (CreditGameSiteActivityAction).
     * Sent to a post, a person saw its sign-in form when one opened within
     * 30 minutes — `post_sign_in_form` on the play, or `signup_form_at` on
     * the recorded visit it came on, which paid visits had before the event
     * — or when they signed up on a post within those 30 minutes, which takes
     * the form; signed up there, by an account credited to the play
     * (`signup_place` post); and confirmed, once that account is.
     *
     * @param  list<list<string>>  $countryGroups  split by group: the countries of each group of the price set but the last, which holds every other country and an unknown one
     * @return array<int|string, GameFunnel>
     */
    public function funnels(GameMetricsScope $scope, ?string $by = null, array $countryGroups = []): array
    {
        if ($by !== null && ! array_key_exists($by, self::GROUPS) && ! array_key_exists($by, self::VISIT_GROUPS)) {
            throw new InvalidArgumentException("[{$by}] is not something a game's figures are split by.");
        }

        $split = fn (Builder $query, string $versionColumn, bool $views = false): Builder => $this->grouped($query, $versionColumn, $by, $scope, $countryGroups, $views);
        [$visitorSql, $visitorBindings] = self::visitorCount();
        $visitors = $this->counts($split($this->viewEvents($scope), 'game_events.version_id', true)->selectRaw($visitorSql, $visitorBindings));
        $person = self::person($scope);
        $attempts = $this->counts(
            $split($this->withConfirmedAccounts($this->attempts($scope)), 'game_attempts.version_id')
                ->selectRaw(
                    "COUNT(*) AS started,
                    SUM(CASE WHEN game_attempts.status = ? THEN 1 ELSE 0 END) AS completed,
                    COUNT(DISTINCT {$person}) AS starters,
                    COUNT(DISTINCT CASE WHEN game_attempts.status = ? THEN {$person} END) AS finishers,
                    COUNT(DISTINCT confirmed_accounts.user_id) AS registered,
                    COUNT(DISTINCT CASE WHEN game_attempts.account_outcome = ? THEN game_attempts.user_id END) AS signed_in,
                    SUM(CASE WHEN game_attempts.goal_reached_at IS NOT NULL THEN 1 ELSE 0 END) AS goal_reached,
                    COUNT(DISTINCT CASE WHEN game_attempts.goal_reached_at IS NOT NULL THEN {$person} END) AS goal_people",
                    [GameAttemptStatus::Completed->value, GameAttemptStatus::Completed->value, GameAccountOutcome::SignedIn->value],
                ),
        );
        $emails = $this->counts(
            $split($this->cohortJoin(GameEmailCapture::query(), 'game_email_captures.attempt_id', $scope), 'game_attempts.version_id')
                ->selectRaw("COUNT(*) AS emails, COUNT(DISTINCT {$person}) AS emailers", []),
        );
        // A way on is the exit the flow's own way on recorded, on a play it sent there: never the
        // link to the feed every screen has, nor the feed a post no longer published falls back to.
        $wayOn = 'game_events.type = ? AND game_events.step_type = ? AND game_attempts.onward_kind = ?';
        $events = $this->counts(
            $split(
                $this->cohortJoin(GameEvent::query(), 'game_events.attempt_id', $scope)->whereIn('game_events.type', array_map(fn (GameEventType $type): string => $type->value, self::FUNNEL_EVENTS)),
                'game_attempts.version_id',
            )->selectRaw(
                "COUNT(DISTINCT CASE WHEN game_events.type = ? THEN game_events.attempt_id END) AS shared,
                COUNT(DISTINCT CASE WHEN game_events.type = ? THEN {$person} END) AS sharers,
                COUNT(DISTINCT CASE WHEN game_events.type = ? THEN {$person} END) AS sign_up_starters,
                COUNT(DISTINCT CASE WHEN game_events.type = ? THEN {$person} END) AS went_to_post,
                COUNT(DISTINCT CASE WHEN game_events.type = ? THEN {$person} END) AS voted_on_post,
                COUNT(DISTINCT CASE WHEN game_events.type = ? THEN {$person} END) AS rated_post,
                COUNT(DISTINCT CASE WHEN {$wayOn} THEN {$person} END) AS went_to_feed,
                COUNT(DISTINCT CASE WHEN game_events.type = ? THEN {$person} END) AS voted_in_feed,
                COUNT(DISTINCT CASE WHEN {$wayOn} THEN {$person} END) AS went_to_page,
                COUNT(DISTINCT CASE WHEN {$wayOn} THEN {$person} END) AS went_to_next_game",
                [
                    GameEventType::ShareClicked->value,
                    GameEventType::ShareClicked->value,
                    GameEventType::RegistrationCtaClicked->value,
                    GameEventType::PostOpened->value,
                    GameEventType::PostVoted->value,
                    GameEventType::PostRated->value,
                    GameEventType::Exit->value, GameOnwardKind::Feed->value, GameOnwardKind::Feed->value,
                    GameEventType::FeedVoted->value,
                    GameEventType::Exit->value, GameOnwardKind::Page->value, GameOnwardKind::Page->value,
                    GameEventType::Exit->value, GameOnwardKind::NextGame->value, GameOnwardKind::NextGame->value,
                ],
            ),
        );
        // From the post to an account, on the plays sent to one: the sign-in form shown within the window —
        // credited as it opens, or marked on the play's own recorded visit — or an account made on a post
        // within it, which took the form; then those accounts, and the confirmed among them.
        [$windowEnd, $windowBindings] = self::minutesAfter('game_attempts.onward_at', self::ON_SITE_MINUTES);
        $signedUp = "post_sign_ups.signed_up_at >= game_attempts.onward_at AND post_sign_ups.signed_up_at <= {$windowEnd}";
        $onPost = $this->counts(
            $split($this->postSignIns($scope), 'game_attempts.version_id')->selectRaw(
                "COUNT(DISTINCT CASE WHEN sign_in_forms.id IS NOT NULL
                    OR (form_visits.signup_form_at >= game_attempts.onward_at AND form_visits.signup_form_at <= {$windowEnd})
                    OR ({$signedUp}) THEN {$person} END) AS saw_sign_in,
                COUNT(DISTINCT CASE WHEN {$signedUp} THEN {$person} END) AS signed_up,
                COUNT(DISTINCT CASE WHEN {$signedUp} AND post_sign_ups.confirmed_at IS NOT NULL THEN {$person} END) AS confirmed",
                [...$windowBindings, ...$windowBindings, ...$windowBindings, ...$windowBindings],
            ),
        );
        $replayers = $this->replayers($this->grouped($this->attempts($scope), 'game_attempts.version_id', $by, $scope, $countryGroups, false), $scope, $by !== null);

        $buckets = array_unique([...array_keys($visitors), ...array_keys($attempts), ...array_keys($emails), ...array_keys($events), ...array_keys($onPost), ...array_keys($replayers)]);
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
                shared: $events[$bucket]['shared'] ?? 0,
                replayers: $replayers[$bucket] ?? 0,
                starters: $attempts[$bucket]['starters'] ?? 0,
                finishers: $attempts[$bucket]['finishers'] ?? 0,
                emailers: $emails[$bucket]['emailers'] ?? 0,
                sharers: $events[$bucket]['sharers'] ?? 0,
                signUpStarters: $events[$bucket]['sign_up_starters'] ?? 0,
                goalReached: $attempts[$bucket]['goal_reached'] ?? 0,
                goalPeople: $attempts[$bucket]['goal_people'] ?? 0,
                wentToPost: $events[$bucket]['went_to_post'] ?? 0,
                sawSignInOnPost: $onPost[$bucket]['saw_sign_in'] ?? 0,
                signedUpOnPost: $onPost[$bucket]['signed_up'] ?? 0,
                confirmedOnPost: $onPost[$bucket]['confirmed'] ?? 0,
                votedOnPost: $events[$bucket]['voted_on_post'] ?? 0,
                ratedPost: $events[$bucket]['rated_post'] ?? 0,
                wentToFeed: $events[$bucket]['went_to_feed'] ?? 0,
                votedInFeed: $events[$bucket]['voted_in_feed'] ?? 0,
                wentToPage: $events[$bucket]['went_to_page'] ?? 0,
                wentToNextGame: $events[$bucket]['went_to_next_game'] ?? 0,
            );
        }

        return $funnels;
    }

    /** The scope's visitors alone, each person once: what the audience switch counts beside each audience. */
    public function visitors(GameMetricsScope $scope): int
    {
        [$sql, $bindings] = self::visitorCount();

        return $this->counts($this->viewEvents($scope)->selectRaw($sql, $bindings))[0]['visitors'] ?? 0;
    }

    /**
     * The people of the scope who finished an attempt of one of the versions
     * given, each person once however many of them they finished: what
     * Email captured is a share of, of the versions that ask for an email.
     *
     * @param  list<int>  $versionIds
     */
    public function finishers(GameMetricsScope $scope, array $versionIds): int
    {
        if ($versionIds === []) {
            return 0;
        }

        return $this->attempts($scope)
            ->whereIn('game_attempts.version_id', $versionIds)
            ->where('game_attempts.status', GameAttemptStatus::Completed->value)
            ->distinct()
            ->count(self::person($scope));
    }

    /**
     * The people of the scope a way on of one of the versions given sent to
     * a post (`post_opened`), each person once however many of them sent
     * them: what Rated the post is a share of, of the versions whose step
     * sending to a post asks for ratings, while another version in view
     * sends there without asking.
     *
     * @param  list<int>  $versionIds
     */
    public function sentToPost(GameMetricsScope $scope, array $versionIds): int
    {
        if ($versionIds === []) {
            return 0;
        }

        return $this->cohortJoin(GameEvent::query(), 'game_events.attempt_id', $scope)
            ->where('game_events.type', GameEventType::PostOpened->value)
            ->whereIn('game_attempts.version_id', $versionIds)
            ->distinct()
            ->count(self::person($scope));
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
     * made in it and how many of those were completed — of one version, when
     * one is named. Periods nobody started in are absent.
     *
     * @return array<int, array{started: int, completed: int}>
     */
    public function periods(int $gameId, ?int $versionId = null): array
    {
        $rows = GameAttempt::query()
            ->join('game_assignments', 'game_assignments.id', '=', 'game_attempts.assignment_id')
            ->where('game_attempts.game_id', $gameId)
            ->when($versionId !== null, fn (Builder $query) => $query->where('game_attempts.version_id', $versionId))
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
     * Per traffic period asked for, and per version, how far the visitors the
     * period sent to it got — what Growth → Experiments compares versions by
     * and a game's Analytics badges its periods by. Each visitor is their
     * assignment in that period, counted once whatever they did on it:
     * assigned, started an attempt from it, completed one, reached a result
     * screen, left an email, reached the flow's goal. A visitor assigned again
     * in a later period is that period's there, and stays this one's here.
     * Accounts are the confirmed accounts credited to those attempts, as
     * everywhere else (withConfirmedAccounts); matured are those signed up
     * before `$maturedBefore`, whose seven days to activate are over, and
     * activated those of them that did. One query for every period asked
     * for; a version nobody was sent to in a period is absent from it.
     *
     * @param  list<int>  $periodIds
     * @return array<int, array<int, PeriodVersionFunnel>>
     */
    public function periodFunnel(array $periodIds, CarbonImmutable $maturedBefore): array
    {
        if ($periodIds === []) {
            return [];
        }

        $rows = $this->withConfirmedAccounts(GameAssignment::query()->leftJoin('game_attempts', 'game_attempts.assignment_id', '=', 'game_assignments.id'))
            ->leftJoin('account_attributions as credited_accounts', 'credited_accounts.user_id', '=', 'confirmed_accounts.user_id')
            ->leftJoin('game_email_captures', 'game_email_captures.attempt_id', '=', 'game_attempts.id')
            ->whereIn('game_assignments.period_id', $periodIds)
            ->groupBy('game_assignments.period_id', 'game_assignments.version_id')
            ->select('game_assignments.period_id', 'game_assignments.version_id')
            ->selectRaw(
                'COUNT(DISTINCT game_assignments.id) AS assigned,
                COUNT(DISTINCT CASE WHEN game_attempts.id IS NOT NULL THEN game_assignments.id END) AS started,
                COUNT(DISTINCT CASE WHEN game_attempts.status = ? THEN game_assignments.id END) AS completed,
                COUNT(DISTINCT CASE WHEN EXISTS (SELECT 1 FROM game_events WHERE game_events.attempt_id = game_attempts.id AND game_events.type = ?) THEN game_assignments.id END) AS result_viewed,
                COUNT(DISTINCT CASE WHEN game_email_captures.id IS NOT NULL THEN game_assignments.id END) AS emailed,
                COUNT(DISTINCT CASE WHEN game_attempts.goal_reached_at IS NOT NULL THEN game_assignments.id END) AS goal,
                COUNT(DISTINCT confirmed_accounts.user_id) AS accounts,
                COUNT(DISTINCT CASE WHEN credited_accounts.signed_up_at < ? THEN confirmed_accounts.user_id END) AS matured,
                COUNT(DISTINCT CASE WHEN credited_accounts.signed_up_at < ? AND credited_accounts.activated_at IS NOT NULL THEN confirmed_accounts.user_id END) AS activated',
                [GameAttemptStatus::Completed->value, GameEventType::ResultViewed->value, $maturedBefore, $maturedBefore],
            )
            ->toBase()
            ->get();
        $periods = [];

        foreach ($rows as $row) {
            $periods[(int) $row->period_id][(int) $row->version_id] = new PeriodVersionFunnel(
                assigned: (int) $row->assigned,
                started: (int) $row->started,
                completed: (int) $row->completed,
                resultViewed: (int) $row->result_viewed,
                emailed: (int) $row->emailed,
                goal: (int) $row->goal,
                accounts: (int) $row->accounts,
                matured: (int) $row->matured,
                activated: (int) $row->activated,
            );
        }

        return $periods;
    }

    /**
     * Per game, over a window: the attempts started and completed, the
     * anonymous visitors, and the new accounts the attempts led to — the
     * library's columns. Games with nothing in the window are absent.
     *
     * @param  list<int>  $gameIds
     * @return array<int, array{started: int, completed: int, visitors: int, registered: int, goal_reached: int}>
     */
    public function library(array $gameIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($gameIds === []) {
            return [];
        }

        $attempts = $this->withConfirmedAccounts(GameAttempt::query())
            ->whereIn('game_attempts.game_id', $gameIds)
            ->where('game_attempts.started_at', '>=', $from)
            ->where('game_attempts.started_at', '<', $to)
            ->groupBy('game_attempts.game_id')
            ->select('game_attempts.game_id')
            ->selectRaw(
                'COUNT(*) AS started,
                SUM(CASE WHEN game_attempts.status = ? THEN 1 ELSE 0 END) AS completed,
                COUNT(DISTINCT confirmed_accounts.user_id) AS registered,
                SUM(CASE WHEN game_attempts.goal_reached_at IS NOT NULL THEN 1 ELSE 0 END) AS goal_reached',
                [GameAttemptStatus::Completed->value],
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
                'goal_reached' => (int) ($row->goal_reached ?? 0),
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

    /** The same, on a `view` event. */
    private static function viewer(GameMetricsScope $scope): string
    {
        return $scope->audience === GameAudience::Anonymous ? 'game_events.visitor_hash' : 'game_events.user_id';
    }

    /** @return array{0: string, 1: list<mixed>} the visitors of `view` events, each person once, as SQL */
    private static function visitorCount(): array
    {
        return [
            'COUNT(DISTINCT CASE WHEN game_events.audience = ? THEN game_events.visitor_hash END)
            + COUNT(DISTINCT CASE WHEN game_events.audience = ? THEN game_events.user_id END) AS visitors',
            [GameAudience::Anonymous->value, GameAudience::Authenticated->value],
        ];
    }

    /**
     * People who started more than one attempt in the scope, by group: each
     * person's attempts — already split, or not — counted first, then those
     * with more than one.
     *
     * @param  Builder<GameAttempt>  $attempts
     * @return array<int|string, int>
     */
    private function replayers(Builder $attempts, GameMetricsScope $scope, bool $split): array
    {
        $identity = self::person($scope);
        $people = $attempts
            ->addSelect("{$identity} as person")
            ->groupBy($identity)
            ->havingRaw('COUNT(*) > ?', [1]);

        $rows = GameAttempt::query()
            ->fromSub($people->toBase(), 'people')
            ->when($split, fn (Builder $query) => $query->groupBy('people.bucket')->select('people.bucket'))
            ->selectRaw('COUNT(*) AS replayers', [])
            ->toBase()
            ->get();

        $replayers = [];

        foreach ($rows as $row) {
            $replayers[$split ? self::bucket($row->bucket) : 0] = (int) $row->replayers;
        }

        return $replayers;
    }

    /** @return Builder<GameEvent> the scope's `view` events, of the people with a visit the filter admits when it narrows */
    private function viewEvents(GameMetricsScope $scope): Builder
    {
        return GameEvent::query()
            ->where('game_events.game_id', $scope->gameId)
            ->where('game_events.type', GameEventType::View->value)
            ->where('game_events.audience', $scope->audience->value)
            ->where('game_events.occurred_at', '>=', $scope->from)
            ->where('game_events.occurred_at', '<', $scope->to)
            ->when($scope->versionId !== null, fn (Builder $query) => $query->where('game_events.version_id', $scope->versionId))
            ->when($scope->visits->narrows(), fn (Builder $query) => $query->whereIn(
                self::viewer($scope),
                TrafficVisit::query()->fromSub($this->people($scope, false), 'visit_people')->select('visit_people.person'),
            ));
    }

    /**
     * The people of the scope's audience with a recorded visit of the game
     * the filter admits — a guest by the browser's hash; a player by an
     * account a browser of such a visit signed up or in on, or by the visit
     * a play of theirs came on — as `person`, with the visit's device and
     * country when asked for: a person who came from two places is there
     * once for each.
     *
     * @return Builder<TrafficVisit>
     */
    private function people(GameMetricsScope $scope, bool $withPlace): Builder
    {
        $place = $withPlace ? ['traffic_visits.'.TrafficDimension::Device->columns()[0], 'traffic_visits.'.TrafficDimension::Country->columns()[0]] : [];
        // Visits of the window only: one that ran into it from before counts, one long over does not.
        $visits = fn (): Builder => TrafficVisit::query()->tracked()->matching($scope->visits)
            ->where('traffic_visits.arrived_at', '<', $scope->to)
            ->where('traffic_visits.last_seen_at', '>=', $scope->from);

        if ($scope->audience === GameAudience::Anonymous) {
            // A guest by a visit of this game, or by the visit a play of theirs came on — a paid click
            // landing on another game holds the browser, and the play is counted by it.
            $plays = $visits()
                ->join('game_attempts', 'game_attempts.traffic_visit_id', '=', 'traffic_visits.id')
                ->where('game_attempts.game_id', $scope->gameId)
                ->select(['traffic_visits.visitor_hash as person', ...$place]);

            return $visits()
                ->where('traffic_visits.game_id', $scope->gameId)
                ->select(['traffic_visits.visitor_hash as person', ...$place])
                ->union($plays);
        }

        $browsers = $visits()
            ->join('account_browsers', 'account_browsers.visitor_hash', '=', 'traffic_visits.visitor_hash')
            ->where('traffic_visits.game_id', $scope->gameId)
            ->select(['account_browsers.user_id as person', ...$place]);

        return $visits()
            ->join('game_attempts', 'game_attempts.traffic_visit_id', '=', 'traffic_visits.id')
            ->where('game_attempts.game_id', $scope->gameId)
            ->whereNotNull('game_attempts.user_id')
            ->select(['game_attempts.user_id as person', ...$place])
            ->union($browsers);
    }

    /** @return Builder<GameAttempt> the attempts started in the scope */
    private function attempts(GameMetricsScope $scope): Builder
    {
        return $this->cohort(GameAttempt::query(), $scope);
    }

    /**
     * Attempts with the confirmed account credited to each, if any
     * (`confirmed_accounts.user_id`): an account counts for the game once it
     * is confirmed, on the play it was credited to when it signed up — on any
     * page of the site, in any session. At most one row per attempt, so the
     * attempts' own counts stay as they are.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  of attempts, or of a table `game_attempts` is joined to
     * @return Builder<TModel>
     */
    private function withConfirmedAccounts(Builder $query): Builder
    {
        $confirmed = AccountAttribution::query()
            ->whereNotNull('confirmed_at')
            ->whereNotNull('credited_attempt_id')
            ->groupBy('credited_attempt_id')
            ->select('credited_attempt_id')
            ->selectRaw('MIN(user_id) AS user_id', []);

        return $query->leftJoinSub($confirmed, 'confirmed_accounts', 'confirmed_accounts.credited_attempt_id', '=', 'game_attempts.id');
    }

    /**
     * The scope's attempts a way on sent to a post, each with what led from
     * there to an account: its `post_sign_in_form` (sign_in_forms), the
     * recorded visit it came on (form_visits), and the accounts credited to
     * it that signed up on a post (post_sign_ups) — an attempt once for each
     * such account, so only distinct people are counted from it.
     *
     * @return Builder<GameAttempt>
     */
    private function postSignIns(GameMetricsScope $scope): Builder
    {
        return $this->attempts($scope)
            ->where('game_attempts.onward_kind', GameOnwardKind::Post->value)
            ->leftJoin('game_events as sign_in_forms', fn (JoinClause $join): JoinClause => $join
                ->on('sign_in_forms.attempt_id', '=', 'game_attempts.id')
                ->where('sign_in_forms.type', '=', GameEventType::PostSignInForm->value))
            ->leftJoin('traffic_visits as form_visits', 'form_visits.id', '=', 'game_attempts.traffic_visit_id')
            ->leftJoin('account_attributions as post_sign_ups', fn (JoinClause $join): JoinClause => $join
                ->on('post_sign_ups.credited_attempt_id', '=', 'game_attempts.id')
                ->where('post_sign_ups.signup_place', '=', AccountSignupPlace::Post->value));
    }

    /**
     * A moment some minutes after a column's, as SQL with its bindings: the
     * one expression each engine writes its own way.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function minutesAfter(string $column, int $minutes): array
    {
        $driver = (new GameAttempt)->getConnection()->getDriverName();

        return match ($driver) {
            'pgsql' => ["({$column} + CAST(? AS interval))", ["{$minutes} minutes"]],
            'mysql', 'mariadb' => ["DATE_ADD({$column}, INTERVAL ? MINUTE)", [$minutes]],
            'sqlite' => ["datetime({$column}, ?)", ["+{$minutes} minutes"]],
            default => throw new LogicException("A game's figures cannot add minutes to a moment on the {$driver} driver."),
        };
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
            ->when($scope->versionId !== null, fn (Builder $inner) => $inner->where('game_attempts.version_id', $scope->versionId))
            // Of a play's own visit, whichever game it landed on.
            ->when($scope->visits->narrows(), fn (Builder $inner) => $inner->whereIn(
                'game_attempts.traffic_visit_id',
                TrafficVisit::query()->tracked()->matching($scope->visits)->select('traffic_visits.id'),
            ));
    }

    /**
     * The query split by version, flow or content set, read from the version
     * each row belongs to; or by the device, country or country group of the
     * recorded visits behind it — a play's own visit, a visitor's visits of
     * the game; unsplit without a group.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<list<string>>  $countryGroups
     * @return Builder<TModel>
     */
    private function grouped(Builder $query, string $versionColumn, ?string $by, GameMetricsScope $scope, array $countryGroups, bool $views): Builder
    {
        if ($by === null) {
            return $query;
        }

        if (array_key_exists($by, self::GROUPS)) {
            $column = self::GROUPS[$by];

            return $query->join('game_versions', 'game_versions.id', '=', $versionColumn)
                ->addSelect("{$column} as bucket")
                ->groupBy($column);
        }

        $views
            ? $query->joinSub($this->people($scope, true), 'visit_people', 'visit_people.person', '=', self::viewer($scope))
            : $query->join('traffic_visits as play_visits', 'play_visits.id', '=', 'game_attempts.traffic_visit_id');
        $column = ($views ? 'visit_people.' : 'play_visits.').self::VISIT_GROUPS[$by]->columns()[0];

        if ($by !== 'group') {
            return $query->addSelect("{$column} as bucket")->groupBy($column);
        }

        [$group, $bindings] = self::countryGroup($column, $countryGroups);

        return $query->selectRaw("{$group} AS bucket", $bindings)->groupBy('bucket');
    }

    /**
     * The index of the group a country is in, as SQL: the groups of the price
     * set but the last in turn, then the last for every other country and an
     * unknown one. A group naming no country takes none.
     *
     * @param  list<list<string>>  $groups
     * @return array{0: string, 1: list<mixed>}
     */
    private static function countryGroup(string $column, array $groups): array
    {
        $cases = [];
        $bindings = [];

        foreach ($groups as $index => $countries) {
            if ($countries !== []) {
                $cases[] = "WHEN {$column} IN (".implode(', ', array_fill(0, count($countries), '?')).') THEN ?';
                array_push($bindings, ...$countries);
                $bindings[] = $index;
            }
        }

        // A CASE needs one WHEN at least: an unknown country is in the last group as well.
        if ($cases === []) {
            $cases[] = "WHEN {$column} IS NULL THEN ?";
            $bindings[] = count($groups);
        }

        return ['CASE '.implode(' ', $cases).' ELSE ? END', [...$bindings, count($groups)]];
    }

    /** A row's bucket as a key: a number for an id or a group's index, the text of a device or country, '' for an unknown country. */
    private static function bucket(mixed $value): int|string
    {
        return is_numeric($value) ? (int) $value : (string) $value;
    }

    /**
     * The rows of a grouped aggregate by bucket (0 unsplit), every figure a
     * whole number.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<int|string, array<string, int>>
     */
    private function counts(Builder $query): array
    {
        $counts = [];

        foreach ($query->toBase()->get() as $row) {
            $values = (array) $row;
            $bucket = array_key_exists('bucket', $values) ? self::bucket($values['bucket']) : 0;
            unset($values['bucket']);
            $counts[$bucket] = array_map(fn (mixed $value): int => (int) $value, $values);
        }

        return $counts;
    }
}
