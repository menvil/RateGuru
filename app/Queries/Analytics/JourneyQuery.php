<?php

namespace App\Queries\Analytics;

use App\Enums\GameEventType;
use App\Enums\UserRole;
use App\Models\AccountAttribution;
use App\Models\AccountBrowser;
use App\Models\Game;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitPost;
use App\Models\User;
use App\Models\UserActivityDay;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Analytics\JourneyTimeline;
use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Capture\PresenceChecks;
use Illuminate\Database\Eloquent\Builder;

/**
 * Analytics → Journeys, read with Eloquent alone: who an ID names, and
 * everything recorded of them — for JourneyTimeline to tell in order.
 *
 *  - find()      a browser by the first hex digits of its visitor hash — a
 *                range of hashes, so the index on each table serves on every
 *                engine — with the account that signed up or signed in in it;
 *                or a member by the account's id, with the browser it signed
 *                up in and the latest it signed in with. Several browsers
 *                sharing the digits come back to choose from;
 *  - journey()   the latest JourneyTimeline::MOST_STEPS of each kind of step
 *                of those browsers (and of the account, when its own steps
 *                belong): the visits with their page and referrer while the
 *                details are kept, the posts their sessions opened, the
 *                versions assigned with each period's shares, the attempts,
 *                the sign-up and share buttons pressed on them, the moments an
 *                email was left — never the address — and the account's
 *                sign-up and the later days it came back; when only the
 *                latest were read, the very first visit and the latest
 *                that began a session from somewhere named too;
 *  - examples()  the latest three confirmed accounts of members, then the
 *                latest two players no account has, as IDs — of the visits a
 *                filter keeps, when one is given; with neither and no
 *                filter, the latest two browsers that visited the site.
 *
 * Read-only.
 */
final class JourneyQuery
{
    /** How many recent players are looked through for two that no account has. */
    private const int PLAYERS_LOOKED_AT = 50;

    /** The most rows of the posts a journey's sessions opened read. */
    public const int MOST_POST_ROWS = 2000;

    /** @var list<string> the columns of a visit a journey reads */
    private const array VISIT_COLUMNS = [
        'id', 'entry_visit_id', 'visitor_hash', 'channel', 'source_id', 'source_key', 'campaign_key', 'creative_key', 'landing_key', 'origin',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'device', 'country', 'city', 'game_id',
        'arrived_at', 'last_seen_at', 'opened_at', 'login_at', 'votes', 'comments', 'posts_created', 'acted_at', 'bot_flags', 'quality_flags', 'seen_at', 'engaged_at',
    ];

    /** @var list<string> the columns of an attempt a journey reads */
    private const array ATTEMPT_COLUMNS = [
        'id', 'game_id', 'version_id', 'assignment_id', 'status', 'rounds_answered', 'score', 'max_score', 'started_at', 'completed_at', 'last_activity_at',
        'goal_reached_at', 'account_outcome', 'account_outcome_at',
    ];

    /** @var list<string> the campaign tags a session can carry */
    private const array TAGS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    /**
     * Who an ID names: the browsers to read and the account the journey
     * names — with whether its own steps belong, which for a browser they do
     * only when the account signed up in it — or, for a visitor ID several
     * browsers share, those browsers to choose from. Null when nobody has it.
     * A member's browsers are the latest JourneyTimeline::MOST_BROWSERS it
     * signed in with, and the one it signed up in; `more` says it has others.
     *
     * @param  array{kind: 'visitor', value: string}|array{kind: 'user', value: int}  $id  as JourneyTimeline::read() reads it
     * @return array{browsers: list<string>, user: int|null, account: bool, more: bool, matches: list<string>}|null
     */
    public function find(array $id): ?array
    {
        if ($id['kind'] === 'user') {
            $user = User::query()->whereKey($id['value'])->where('role', UserRole::User->value)->value('id');

            if ($user === null) {
                return null;
            }

            // The latest browsers it signed in with, oldest first: no more than a journey's reads can name.
            $browsers = AccountBrowser::query()->where('user_id', $user)->orderByDesc('linked_at')->orderByDesc('id')
                ->limit(JourneyTimeline::MOST_BROWSERS + 1)->pluck('visitor_hash')->all();
            $more = count($browsers) > JourneyTimeline::MOST_BROWSERS;
            $browsers = array_reverse(array_slice($browsers, 0, JourneyTimeline::MOST_BROWSERS));
            $signedUp = AccountAttribution::query()->where('user_id', $user)->value('visitor_hash');

            return ['browsers' => array_values(array_unique(array_filter([...$browsers, $signedUp]))), 'user' => (int) $user, 'account' => true, 'more' => $more, 'matches' => []];
        }

        $hashes = $this->browsers((string) $id['value']);

        if ($hashes === []) {
            return null;
        }

        if (count($hashes) > 1) {
            return ['browsers' => [], 'user' => null, 'account' => false, 'more' => false, 'matches' => $hashes];
        }

        $signedUp = AccountAttribution::query()->where('visitor_hash', $hashes[0])->orderBy('id')->value('user_id');
        $user = $signedUp ?? AccountBrowser::query()->where('visitor_hash', $hashes[0])->orderByDesc('linked_at')->orderByDesc('id')->value('user_id');

        return ['browsers' => $hashes, 'user' => $user === null ? null : (int) $user, 'account' => $signedUp !== null, 'more' => false, 'matches' => []];
    }

    /**
     * Everything recorded of the browsers given — and of the account, by
     * its id, for its attempts, versions, sign-up and days — each kind of
     * step the latest JourneyTimeline::MOST_STEPS, and the latest
     * MOST_POST_ROWS rows of the posts their sessions opened, as
     * JourneyTimeline reads a journey (without its `kind`, `browsers`,
     * `user`, `account` and `more`). When there was more than was read, the
     * browsers' very first visit (`first`) and their latest visit that began a
     * session from somewhere named, neither Direct nor inside the site
     * (`lastNamed`), whenever it was; each null otherwise. The attempt the
     * sign-up is credited to, when it isn't among the attempts read
     * (`creditedAttempt`, its game and version named too); null otherwise.
     *
     * @param  list<string>  $browsers  visitor hashes
     * @param  int|null  $userId  the member whose own records count too: their attempts and assignments signed in
     * @param  int|null  $accountId  the account whose sign-up and days belong
     * @return array<string, mixed>
     */
    public function journey(array $browsers, ?int $userId, ?int $accountId): array
    {
        $most = JourneyTimeline::MOST_STEPS;
        $visits = $browsers === [] ? collect() : TrafficVisit::query()->whereIn('visitor_hash', $browsers)
            ->with('detail:visit_id,landing_path,referrer_host')
            ->orderByDesc('arrived_at')->orderByDesc('id')->limit($most + 1)->get(self::VISIT_COLUMNS);
        $cut = $visits->count() > $most;
        $visits = $visits->take($most);

        $mine = function (Builder $query) use ($browsers, $userId): void {
            $query->whereIn('visitor_hash', $browsers)->when($userId !== null, fn (Builder $query) => $query->orWhere('user_id', $userId));
        };
        $attempts = $browsers === [] && $userId === null ? collect() : GameAttempt::query()->where($mine)
            ->orderByDesc('started_at')->orderByDesc('id')->limit($most + 1)
            ->get(self::ATTEMPT_COLUMNS);
        $assignments = $browsers === [] && $userId === null ? collect() : GameAssignment::query()->where($mine)
            ->orderByDesc('assigned_at')->orderByDesc('id')->limit($most + 1)
            ->get(['id', 'game_id', 'version_id', 'period_id', 'assigned_at', 'ended_at']);
        $cut = $cut || $attempts->count() > $most || $assignments->count() > $most;
        $attempts = $attempts->take($most);
        $assignments = $assignments->take($most);
        $attemptIds = $attempts->pluck('id')->all();

        $events = $attemptIds === [] ? collect() : GameEvent::query()->whereIn('attempt_id', $attemptIds)
            ->whereIn('type', [GameEventType::RegistrationCtaClicked->value, GameEventType::ShareClicked->value])
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit($most)->get(['attempt_id', 'type', 'provider', 'occurred_at']);
        // The moment alone: the address left is never read here.
        $emails = $attemptIds === [] ? collect() : GameEmailCapture::query()->whereIn('attempt_id', $attemptIds)->get(['attempt_id', 'captured_at']);
        // The latest rows, so the latest sessions keep their posts — when there were more, the oldest are left out — then oldest first.
        $posts = $visits->isEmpty() ? collect() : TrafficVisitPost::query()->whereIn('visit_id', $visits->pluck('id')->all())
            ->orderByDesc('first_at')->orderByDesc('visit_id')->orderByDesc('post_id')->limit(self::MOST_POST_ROWS + 1)
            ->get(['visit_id', 'post_id', 'views', 'shared_at', 'voted', 'commented', 'first_at']);
        $postsCut = $posts->count() > self::MOST_POST_ROWS;
        $posts = $posts->take(self::MOST_POST_ROWS)->reverse()->values();

        $attribution = $accountId === null ? null : AccountAttribution::query()->where('user_id', $accountId)
            ->first(['id', 'user_id', 'visitor_hash', 'signup_method', 'signup_place', 'signed_up_at', 'confirmed_at', 'credited_attempt_id', 'credited_visit_id', 'expired_visit_id', 'activated_at']);
        $days = $accountId === null ? collect() : UserActivityDay::query()->where('user_id', $accountId)->where('day_number', '>=', 1)
            ->orderByDesc('day')->limit($most)->get(['day', 'day_number', 'voted', 'commented', 'posted', 'played', 'first_at']);

        // The visits the sign-up names, when they aren't among those read; and the very first, when only the latest were.
        $read = $visits->pluck('id')->all();
        $named = array_values(array_diff(array_filter([$attribution?->credited_visit_id, $attribution?->expired_visit_id]), $read));
        $credited = $named === [] ? collect() : TrafficVisit::query()->whereKey($named)->with('detail:visit_id,landing_path,referrer_host')->get(self::VISIT_COLUMNS);
        // The attempt the sign-up is credited to, when it isn't among those read: older than the latest, it is still what the account is credited to.
        $creditedAttemptId = $attribution?->credited_attempt_id;
        $creditedAttempt = $creditedAttemptId === null || $attempts->contains('id', $creditedAttemptId) ? null
            : GameAttempt::query()->whereKey($creditedAttemptId)->first(self::ATTEMPT_COLUMNS);
        $first = $cut && $browsers !== [] ? TrafficVisit::query()->whereIn('visitor_hash', $browsers)->with('detail:visit_id,landing_path,referrer_host')
            ->orderBy('arrived_at')->orderBy('id')->first(self::VISIT_COLUMNS) : null;
        // The last non-direct touch, which may be older than the visits read: the latest visit that began a session from somewhere named.
        $lastNamed = $cut && $browsers !== [] ? TrafficVisit::query()->whereIn('visitor_hash', $browsers)
            ->where(fn (Builder $query) => $query->whereColumn('entry_visit_id', 'id')->orWhereNull('entry_visit_id'))
            ->whereNotIn('source_key', [AnalyticsSource::DIRECT, AnalyticsSource::INTERNAL])
            ->with('detail:visit_id,landing_path,referrer_host')
            ->orderByDesc('arrived_at')->orderByDesc('id')->first(self::VISIT_COLUMNS) : null;

        $periods = $assignments->isEmpty() ? collect() : GameTrafficPeriod::query()->whereKey($assignments->pluck('period_id')->unique()->all())
            ->with(['versions:game_versions.id,game_versions.number', 'source:id,name', 'campaign:id,name,external_id'])->get(['id', 'game_id', 'started_at', 'source_id', 'campaign_id']);
        $gameIds = array_values(array_unique(array_filter([...$visits->pluck('game_id')->all(), ...$credited->pluck('game_id')->all(), ...$attempts->pluck('game_id')->all(), ...$assignments->pluck('game_id')->all(), $creditedAttempt?->game_id])));
        $versionIds = array_values(array_unique(array_filter([...$attempts->pluck('version_id')->all(), ...$assignments->pluck('version_id')->all(), $creditedAttempt?->version_id])));

        return [
            'visits' => $visits->map(fn (TrafficVisit $visit): array => self::visit($visit))->values()->all(),
            'first' => $first === null ? null : self::visit($first),
            'lastNamed' => $lastNamed === null ? null : self::visit($lastNamed),
            'credited' => $credited->map(fn (TrafficVisit $visit): array => self::visit($visit))->values()->all(),
            'posts' => $posts->map(fn (TrafficVisitPost $row): array => [
                'visit' => (int) $row->visit_id,
                'post' => (int) $row->post_id,
                'views' => (int) $row->views,
                'shared' => $row->shared_at !== null,
                'voted' => (bool) $row->voted,
                'commented' => (bool) $row->commented,
                'at' => $row->first_at->utc(),
            ])->all(),
            'attempts' => $attempts->map(fn (GameAttempt $attempt): array => self::attempt($attempt))->values()->all(),
            'creditedAttempt' => $creditedAttempt === null ? null : self::attempt($creditedAttempt),
            'assignments' => $assignments->map(fn (GameAssignment $assignment): array => [
                'id' => (int) $assignment->id,
                'game' => (int) $assignment->game_id,
                'version' => (int) $assignment->version_id,
                'period' => (int) $assignment->period_id,
                'at' => $assignment->assigned_at->utc(),
                'ended' => $assignment->ended_at?->utc(),
            ])->values()->all(),
            'periods' => $periods->mapWithKeys(fn (GameTrafficPeriod $period): array => [(int) $period->id => [
                'from' => $period->started_at->utc(),
                'scope' => $period->scopeLabel(),
                'shares' => $period->versions->map(fn (GameVersion $version): array => ['number' => (int) $version->number, 'percent' => (int) $period->shares()[(int) $version->id]])->values()->all(),
            ]])->all(),
            'events' => $events->map(fn (GameEvent $event): array => [
                'attempt' => (int) $event->attempt_id,
                'type' => $event->type->value,
                'provider' => $event->provider,
                'at' => $event->occurred_at->utc(),
            ])->values()->all(),
            'emails' => $emails->map(fn (GameEmailCapture $email): array => ['attempt' => (int) $email->attempt_id, 'at' => $email->captured_at->utc()])->values()->all(),
            'attribution' => $attribution === null ? null : [
                'signedUp' => $attribution->signed_up_at->utc(),
                'browser' => $attribution->visitor_hash,
                'confirmed' => $attribution->confirmed_at?->utc(),
                'method' => $attribution->signup_method?->value,
                'place' => $attribution->signup_place?->value,
                'visit' => $attribution->credited_visit_id,
                'attempt' => $attribution->credited_attempt_id,
                'expired' => $attribution->expired_visit_id,
                'activated' => $attribution->activated_at?->utc(),
            ],
            'days' => $days->map(fn (UserActivityDay $day): array => [
                'day' => (string) $day->day,
                'number' => (int) $day->day_number,
                'voted' => (bool) $day->voted,
                'commented' => (bool) $day->commented,
                'posted' => (bool) $day->posted,
                'played' => (bool) $day->played,
                'at' => $day->first_at->utc(),
            ])->values()->all(),
            'games' => $gameIds === [] ? [] : Game::query()->whereKey($gameIds)->get(['id', 'title', 'slug'])
                ->mapWithKeys(fn (Game $game): array => [(int) $game->id => ['title' => (string) $game->title, 'slug' => (string) $game->slug]])->all(),
            'versions' => $versionIds === [] ? [] : GameVersion::query()->whereKey($versionIds)->with('flow:id,name')->get(['id', 'number', 'flow_id', 'goal', 'round_count'])
                ->mapWithKeys(fn (GameVersion $version): array => [(int) $version->id => [
                    'number' => (int) $version->number,
                    'flow' => $version->flow?->name,
                    'goal' => $version->goal?->label(),
                    'rounds' => (int) $version->round_count,
                ]])->all(),
            'cut' => $cut || $postsCut,
        ];
    }

    /**
     * The journeys worth showing first, as IDs: the latest three confirmed
     * accounts of members, then the latest two players no account has —
     * with a filter, only an account credited to a visit it keeps and a
     * player who started a game on one. With no filter and neither, the
     * latest two browsers that visited the site.
     *
     * @return list<string>
     */
    public function examples(?AnalyticsFilter $filter = null): array
    {
        $accounts = AccountAttribution::query()->whereNotNull('confirmed_at')
            ->whereIn('user_id', User::query()->select('id')->where('role', UserRole::User->value));

        if ($filter !== null) {
            $accounts->whereIn('credited_visit_id', $this->slice($filter)->select('id'));
        }

        $accounts = $accounts->orderByDesc('signed_up_at')->orderByDesc('id')->limit(3)->pluck('user_id')
            ->map(fn (mixed $id): string => JourneyTimeline::userId((int) $id))->all();

        $players = $filter === null
            ? GameAttempt::query()->whereNull('user_id')->whereNotNull('visitor_hash')->whereNotIn('visitor_hash', AccountBrowser::query()->select('visitor_hash'))
                ->orderByDesc('id')->limit(self::PLAYERS_LOOKED_AT)->pluck('visitor_hash')
            : $this->slice($filter)->whereNotNull('started_at')->whereNotIn('visitor_hash', AccountBrowser::query()->select('visitor_hash'))
                ->orderByDesc('arrived_at')->orderByDesc('id')->limit(self::PLAYERS_LOOKED_AT)->pluck('visitor_hash');

        // With no account and no player to show, a visit of the site is a journey too: the latest visitors'.
        if ($filter === null && $accounts === [] && $players->isEmpty()) {
            $players = TrafficVisit::query()->orderByDesc('id')->limit(self::PLAYERS_LOOKED_AT)->pluck('visitor_hash');
        }

        return [...$accounts, ...$players->unique()->take(2)->map(fn (mixed $hash): string => JourneyTimeline::visitorId((string) $hash))->values()->all()];
    }

    /**
     * The browsers whose visitor hash begins with the hex digits given, one
     * more than JourneyTimeline lists at most, from the visits, the attempts
     * and the assignments: a range of hashes — the digits padded with the
     * least and the greatest hex digit — which every engine's index serves,
     * where a prefix pattern would not.
     *
     * @return list<string>
     */
    private function browsers(string $digits): array
    {
        $from = str_pad($digits, 64, '0');
        $to = str_pad($digits, 64, 'f');
        $limit = JourneyTimeline::MOST_MATCHES + 1;
        $hashes = [];

        foreach ([TrafficVisit::query(), GameAttempt::query(), GameAssignment::query()] as $query) {
            array_push($hashes, ...$query->where('visitor_hash', '>=', $from)->where('visitor_hash', '<=', $to)
                ->distinct()->orderBy('visitor_hash')->limit($limit)->pluck('visitor_hash')->all());
        }

        $hashes = array_values(array_unique(array_map('strval', $hashes)));
        sort($hashes);

        return array_slice($hashes, 0, $limit);
    }

    /**
     * The visits an Analytics filter keeps: those of its days that ran in a
     * browser, of its device, source, campaign and path.
     *
     * @return Builder<TrafficVisit>
     */
    private function slice(AnalyticsFilter $filter): Builder
    {
        $query = TrafficVisit::query()
            ->whereBetween('network_day', [$filter->fromDay(), $filter->toDay()])
            ->where('arrived_at', '>=', $filter->arrivedFrom())
            ->where('arrived_at', '<', $filter->arrivedBefore())
            ->whereNotNull('opened_at');

        foreach ($filter->conditions() as [$column, $value]) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }

    /**
     * A visit as a journey reads it: its session, where it came from and
     * by what, its page and place, and what its member did on it.
     *
     * @return array<string, mixed>
     */
    private static function visit(TrafficVisit $visit): array
    {
        $tags = [];

        foreach (self::TAGS as $tag) {
            $value = $visit->getAttribute($tag);

            if (is_string($value) && $value !== '') {
                $tags[$tag] = $value;
            }
        }

        $detail = $visit->detail;

        return [
            'id' => (int) $visit->id,
            'entry' => (int) ($visit->entry_visit_id ?? $visit->id),
            'hash' => (string) $visit->visitor_hash,
            'at' => $visit->arrived_at->utc(),
            'seen' => $visit->last_seen_at->utc(),
            'opened' => $visit->opened_at !== null,
            'source' => $visit->source_key ?? ($visit->source_id !== null ? AnalyticsSource::paid((int) $visit->source_id) : AnalyticsSource::DIRECT),
            'campaign' => $visit->campaign_key,
            'creative' => $visit->creative_key,
            'landing' => $visit->landing_key,
            'tags' => $tags,
            'origin' => $visit->origin,
            'path' => $detail?->landing_path,
            'referrer' => $detail?->referrer_host,
            'device' => $visit->device->value,
            'country' => $visit->country,
            'city' => $visit->city,
            'game' => $visit->game_id,
            'login' => $visit->login_at?->utc(),
            'votes' => (int) $visit->votes,
            'comments' => (int) $visit->comments,
            'posts' => (int) $visit->posts_created,
            'acted' => $visit->acted_at?->utc(),
            'flags' => [...BotSignals::words((int) $visit->bot_flags), ...PresenceChecks::words((int) $visit->quality_flags)],
            'onScreen' => $visit->seen_at?->utc(),
            'engagedAt' => $visit->engaged_at?->utc(),
        ];
    }

    /**
     * An attempt as a journey reads it: its game and version, how far it
     * got, and what became of its account.
     *
     * @return array<string, mixed>
     */
    private static function attempt(GameAttempt $attempt): array
    {
        return [
            'id' => (int) $attempt->id,
            'game' => (int) $attempt->game_id,
            'version' => (int) $attempt->version_id,
            'status' => $attempt->status->value,
            'rounds' => (int) $attempt->rounds_answered,
            'score' => $attempt->score,
            'max' => (int) $attempt->max_score,
            'started' => $attempt->started_at->utc(),
            'completed' => $attempt->completed_at?->utc(),
            'last' => $attempt->last_activity_at->utc(),
            'goal' => $attempt->goal_reached_at?->utc(),
            'outcome' => $attempt->account_outcome?->value,
            'outcomeAt' => $attempt->account_outcome_at?->utc(),
        ];
    }
}
