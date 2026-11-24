<?php

namespace App\Support\Analytics;

use App\Enums\AccountSignupPlace;
use App\Enums\TrafficDevice;
use App\Support\Geo\Countries;
use Carbon\CarbonImmutable;

/**
 * One visitor's or one member's way through the site, as Analytics →
 * Journeys draws it from what JourneyQuery read: every step recorded of the
 * browsers it is read for — each visit, a session's first with where it came
 * from (its source, campaign tags, page and place), the posts each session
 * opened and shared in one line, and what its member voted, commented and
 * made in another, at the moment they first did, the versions the games
 * gave, the attempts and how far each got, the sign-up and share buttons
 * pressed, an email left (never the address), signing in, and for an account
 * its sign-up, confirmation (with the sign-up, when Google or Facebook
 * confirmed it at once), activation and the later days it came back —
 * oldest first, at most the latest MOST_STEPS. Then what brought the browser
 * and what the account is credited to (Attribution) and who it is
 * (Identity).
 *
 * A visitor is a browser, named by the first hex of its visitor hash
 * (`visitor_8f31c2ab`); a member by the account's id (`user_3814`), whose
 * journey is every browser it signed up or signed in with. A browser's own
 * journey carries the account's steps only when the account signed up in it.
 *
 * The journey read is a plain array (JourneyQuery::journey()): `kind`
 * (visitor or user), `browsers` (the hashes read), `user` (the account the
 * journey names, if any), `account` (whether the account's own steps belong),
 * `more` (whether the member signed in with more browsers than were read),
 * `visits`, `posts`, `attempts`, `assignments` (with when each `ended`, if
 * it did), `periods`, `events`,
 * `emails`, `attribution`, `days`, `first` (the earliest visit, when the
 * visits read are only the latest), `lastNamed` (then, the latest visit that
 * began a session from somewhere named), `credited` (visits named by the
 * attribution and not among them), `creditedAttempt` (the attempt it names,
 * when not among the attempts read), `games`, `versions` and `cut` (whether
 * there was more than was read). Pure: every moment given is UTC.
 */
final class JourneyTimeline
{
    /** The most steps a journey shows: the latest. */
    public const int MOST_STEPS = 200;

    /** The most browsers a visitor ID lists to choose from, where several share its digits. */
    public const int MOST_MATCHES = 10;

    /** The most browsers a member signed in with that their journey reads: the latest. */
    public const int MOST_BROWSERS = 500;

    /** The fewest hex digits of a visitor hash a visitor ID names: as many as an ID shows. */
    public const int VISITOR_DIGITS = 8;

    /** The hex digits a browser is named by where several share an ID's digits. */
    public const int LONG_DIGITS = 16;

    /**
     * How a step ranks against another of the same moment: the arrival first,
     * what a member did once the account they did it with existed, a goal
     * after what reached it, the activation last.
     */
    private const array RANK = ['visit' => 0, 'assigned' => 1, 'started' => 2, 'posts' => 3, 'done' => 4, 'pressed' => 5, 'signin' => 6, 'signup' => 6, 'claimed' => 7, 'confirmed' => 8, 'acted' => 9, 'goal' => 10, 'activated' => 11, 'returned' => 11];

    /** How long after signing up with Google or Facebook a confirmation still counts as part of it: drawn as one step. */
    private const int AT_ONCE_SECONDS = 60;

    /** @var list<array<string, mixed>> the visits read and any the attribution names, oldest first */
    private readonly array $visits;

    /** @var array<string, mixed>|null the earliest visit of the browsers */
    private readonly ?array $first;

    /**
     * @param  array<string, mixed>  $journey  as JourneyQuery::journey() reads it, with `kind`, `browsers`, `user` and `account`
     * @param  array<int, string>  $sourceNames  the paid sources' names, by id
     * @param  array<int, string>  $campaignNames  the paid campaigns' names, by id
     */
    public function __construct(
        private readonly array $journey,
        private readonly array $sourceNames = [],
        private readonly array $campaignNames = [],
    ) {
        $visits = $journey['visits'];
        usort($visits, fn (array $a, array $b): int => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);
        $this->visits = $visits;
        $this->first = $journey['first'] ?? ($visits[0] ?? null);
    }

    /**
     * An ID as it is typed or named by an address, when it is one: a visitor
     * by at least the hex digits an ID shows of its hash, or a member by the
     * account's id — any case, around any spaces. Null for anything else.
     *
     * @return array{kind: 'visitor', value: string}|array{kind: 'user', value: int}|null
     */
    public static function read(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        if (preg_match('/^visitor_([0-9a-f]{'.self::VISITOR_DIGITS.',64})$/', $value, $match) === 1) {
            return ['kind' => 'visitor', 'value' => $match[1]];
        }

        if (preg_match('/^user_([1-9]\d{0,17})$/', $value, $match) === 1) {
            return ['kind' => 'user', 'value' => (int) $match[1]];
        }

        return null;
    }

    /** A browser's ID: `visitor_` and the first hex digits of its hash. */
    public static function visitorId(string $hash, int $digits = self::VISITOR_DIGITS): string
    {
        return 'visitor_'.substr($hash, 0, $digits);
    }

    /** A member's ID. */
    public static function userId(int $id): string
    {
        return 'user_'.$id;
    }

    /**
     * What the tab draws: the journey's name and what became of it, its
     * steps and whether earlier ones were left out, the touches of
     * Attribution, the rows of Identity and what Identity says of them.
     *
     * @return array{label: string, status: string, entries: list<array{time: string, icon: string, title: string, body: string, meta: string|null}>, cut: bool, touches: list<array{label: string, value: string, note: string}>, identity: list<array{icon: string, label: string, value: string}>, note: string}
     */
    public function view(): array
    {
        $steps = $this->steps();
        $cut = (bool) ($this->journey['cut'] ?? false) || count($steps) > self::MOST_STEPS;
        $steps = array_slice($steps, -self::MOST_STEPS);
        $years = array_unique(array_map(fn (array $step): string => $step['at']->format('Y'), $steps));
        $format = count($years) > 1 ? 'd M Y H:i' : 'd M H:i';

        return [
            'label' => $this->label(),
            'status' => implode(' · ', [$this->status(), self::count($this->sessions(), 'session'), self::count(count($this->journey['attempts']), 'attempt')]),
            'entries' => array_map(fn (array $step): array => [
                'time' => $step['at']->format($format),
                'icon' => $step['icon'],
                'title' => $step['title'],
                'body' => $step['body'],
                'meta' => $step['meta'],
            ], $steps),
            'cut' => $cut,
            'touches' => $this->touches(),
            'identity' => $this->identity(),
            'note' => $this->journey['kind'] === 'user'
                ? (($this->journey['more'] ?? false)
                    ? 'The latest '.self::MOST_BROWSERS.' browsers '.self::userId((int) $this->journey['user']).' signed in with, and the one it signed up in, together: signing in is the only thing that links them. It signed in with more, which aren’t read.'
                    : 'Every browser '.self::userId((int) $this->journey['user']).' signed up or signed in with, together: signing in is the only thing that links them.')
                    .' Visitor, session and user IDs are separate. No fingerprinting or cross-device matching. Times are UTC.'
                : 'One browser, by its visitor ID. Visitor, session and user IDs are separate: a browser’s visits join an account only once it signs up or signs in there. No fingerprinting or cross-device matching. Times are UTC.',
        ];
    }

    /** The journey's name: the browser and the account, or the account and its browsers. */
    private function label(): string
    {
        $browsers = $this->journey['browsers'];
        $user = $this->journey['user'];

        if ($this->journey['kind'] === 'visitor') {
            return self::visitorId($browsers[0]).' · '.($user === null ? 'not registered' : self::userId((int) $user));
        }

        return match (count($browsers)) {
            0 => self::userId((int) $user).' · no browser recorded',
            1 => self::visitorId($browsers[0]).' · '.self::userId((int) $user),
            default => self::userId((int) $user).' · '.(($this->journey['more'] ?? false) ? 'more than ' : '').count($browsers).' browsers',
        };
    }

    /** What became of the visitor or the account. */
    private function status(): string
    {
        $attribution = $this->account();

        return match (true) {
            $attribution !== null && $attribution['confirmed'] !== null && $attribution['activated'] !== null => 'Registered and activated',
            $attribution !== null && $attribution['confirmed'] !== null => 'Registered',
            $attribution !== null => 'Signed up · not confirmed',
            $this->journey['user'] !== null && $this->journey['kind'] === 'visitor' => 'Signed in as '.self::userId((int) $this->journey['user']),
            $this->journey['user'] !== null => 'Member',
            $this->journey['emails'] !== [] => 'Anonymous · email captured',
            default => 'Anonymous',
        };
    }

    /**
     * The account's sign-up, when its steps belong to this journey.
     *
     * @return array<string, mixed>|null
     */
    private function account(): ?array
    {
        return $this->journey['account'] ? $this->journey['attribution'] : null;
    }

    /** How many sessions the visits read began: each visit belongs to the one its entry began. */
    private function sessions(): int
    {
        return count(array_unique(array_map(fn (array $visit): int => $visit['entry'], $this->visits)));
    }

    /**
     * Every step, oldest first — of the same moment, the arrival first and
     * the activation last.
     *
     * @return list<array{at: CarbonImmutable, rank: int, order: int, icon: string, title: string, body: string, meta: string|null}>
     */
    private function steps(): array
    {
        $steps = [];
        $add = function (CarbonImmutable $at, string $rank, string $icon, string $title, string $body, ?string $meta = null) use (&$steps): void {
            $steps[] = ['at' => $at, 'rank' => self::RANK[$rank], 'order' => count($steps), 'icon' => $icon, 'title' => $title, 'body' => $body, 'meta' => $meta];
        };

        $this->visitSteps($add);
        $this->postSteps($add);
        $this->gameSteps($add);
        $this->accountSteps($add);

        usort($steps, fn (array $a, array $b): int => [$a['at'], $a['rank'], $a['order']] <=> [$b['at'], $b['rank'], $b['order']]);

        return $steps;
    }

    /** Each visit: where a session came from, or the page a session went on to; and signing in on it. */
    private function visitSteps(callable $add): void
    {
        foreach ($this->visits as $visit) {
            $starts = $visit['entry'] === $visit['id'];
            [$icon, $title, $body] = $starts ? $this->arrival($visit) : $this->moveOn($visit);
            $add($visit['at'], 'visit', $icon, $title, $body.($visit['opened'] ? '' : ' The page never ran in a browser.'), $this->visitMeta($visit, $starts));

            if ($visit['login'] !== null) {
                $add($visit['login'], 'signin', 'user-check', 'Signed in', 'To an account the browser already had: a sign-in, not a new account.');
            }
        }
    }

    /**
     * A session's first visit: where it came from, and what said so.
     *
     * @param  array<string, mixed>  $visit
     * @return array{0: string, 1: string, 2: string}
     */
    private function arrival(array $visit): array
    {
        $key = $visit['source'];
        $verb = $this->first !== null && $this->first['id'] === $visit['id'] ? 'Arrived' : 'Returned';
        $firstKey = $this->first['source'] ?? null;
        $kept = $verb === 'Returned' && $firstKey !== null && $firstKey !== $key && $firstKey !== AnalyticsSource::DIRECT
            ? ' The '.AnalyticsSource::label($firstKey, $this->sourceNames).' first touch is kept.'
            : '';

        if (AnalyticsSource::paidSourceId($key) !== null) {
            return ['megaphone', $verb.' from '.AnalyticsSource::label($key, $this->sourceNames), implode(' · ', array_filter(['Paid click', $this->campaign($visit), $visit['creative']])).'.'.$kept];
        }

        return match ($key) {
            AnalyticsSource::DIRECT => ['log-in', $verb.' with no attribution', 'No tags and no referrer. Counted as Direct / Unknown.'.$kept],
            AnalyticsSource::SHARED => ['share-2', $verb.' from a shared result', 'Opened a result another player shared.'.$kept],
            AnalyticsSource::INTERNAL => ['log-in', $verb.' from inside the site', 'Came from another page of the site.'],
            default => [AnalyticsSource::medium($key) === 'email' ? 'mail' : 'log-in', $verb.' from '.AnalyticsSource::label($key, $this->sourceNames), $this->recognised($visit).$kept],
        };
    }

    /**
     * A later visit of a session: the page it went on to.
     *
     * @param  array<string, mixed>  $visit
     * @return array{0: string, 1: string, 2: string}
     */
    private function moveOn(array $visit): array
    {
        $game = $visit['game'] === null ? null : ($this->journey['games'][$visit['game']]['title'] ?? 'a game');
        $page = $game ?? ($visit['path'] ?? 'another page');

        return ['arrow-right', 'Went on to '.$page, 'Same session: its visits keep the source it began with.'];
    }

    /**
     * What told a network's visit apart: its campaign tags, or the referrer.
     *
     * @param  array<string, mixed>  $visit
     */
    private function recognised(array $visit): string
    {
        if ($visit['tags'] !== []) {
            return 'Campaign tags: '.implode(' · ', array_map(fn (string $tag, string $value): string => $tag.'='.$value, array_keys($visit['tags']), $visit['tags'])).'.';
        }

        $host = $visit['referrer'] ?? $visit['origin'];

        return $host === null ? 'No campaign tags.' : 'No campaign tags. Source inferred from the referrer '.$host.'.';
    }

    /**
     * A visit's session, page, device and place — and its browser, when the
     * journey has more than one.
     *
     * @param  array<string, mixed>  $visit
     */
    private function visitMeta(array $visit, bool $starts): string
    {
        $slug = $visit['game'] === null ? null : ($this->journey['games'][$visit['game']]['slug'] ?? null);
        $path = $visit['path'] ?? match (true) {
            $starts && $visit['landing'] !== null => LandingRoute::path($visit['landing'], $slug),
            $slug !== null => '/games/'.$slug,
            default => null,
        };

        return implode(' · ', array_filter([
            'session_'.$visit['entry'],
            count($this->journey['browsers']) > 1 ? self::visitorId($visit['hash']) : null,
            $path,
            TrafficDevice::tryFrom((string) $visit['device'])?->label(),
            self::place($visit),
        ]));
    }

    /**
     * The posts each session opened — each post once, however many of the
     * session's visits did so — with how often and how many were shared, in
     * one line at the first opened (or, when it opened none, the posts it
     * shared without opening them); and the votes, comments and posts its
     * member made on its visits in another, at the moment of the first
     * (actedAt()). A post voted on or commented on from the feed has a row
     * with no view: it is in the second line, never counted opened.
     */
    private function postSteps(callable $add): void
    {
        $visits = [];

        foreach ($this->journey['visits'] as $visit) {
            $visits[$visit['id']] = $visit;
        }

        $blank = fn (CarbonImmutable $at): array => ['at' => $at, 'opened' => [], 'openedAt' => null, 'views' => 0, 'shared' => [], 'sharedAt' => null,
            'voted' => [], 'commented' => [], 'actedOn' => [], 'votes' => 0, 'comments' => 0, 'made' => 0, 'acted' => null, 'untimed' => false];
        $earliest = fn (?CarbonImmutable $kept, CarbonImmutable $at): CarbonImmutable => $kept === null || $at < $kept ? $at : $kept;
        $sessions = [];

        foreach ($this->journey['posts'] as $row) {
            $entry = $visits[$row['visit']]['entry'] ?? null;

            if ($entry === null) {
                continue;
            }

            $session = $sessions[$entry] ?? $blank($row['at']);
            $session['at'] = $earliest($session['at'], $row['at']);
            $session['views'] += $row['views'];

            if ($row['views'] > 0) {
                $session['opened'][$row['post']] = true;
                $session['openedAt'] = $earliest($session['openedAt'], $row['at']);
            }

            if ($row['shared']) {
                $session['shared'][$row['post']] = true;
                $session['sharedAt'] = $earliest($session['sharedAt'], $row['at']);
            }

            foreach (['voted', 'commented'] as $did) {
                if ($row[$did]) {
                    $session[$did][$row['post']] = true;
                    $session['actedOn'][$row['post']] = true;
                }
            }

            $sessions[$entry] = $session;
        }

        foreach ($this->journey['visits'] as $visit) {
            if ($visit['votes'] + $visit['comments'] + $visit['posts'] === 0) {
                continue;
            }

            $session = $sessions[$visit['entry']] ?? $blank($this->sessionStart($visit['entry'], $visit['at']));
            $session['votes'] += $visit['votes'];
            $session['comments'] += $visit['comments'];
            $session['made'] += $visit['posts'];
            $acted = $visit['acted'] ?? null;

            // A visit that acted without keeping when acted before any that kept it: the moment kept is then not the first.
            if ($acted !== null) {
                $session['acted'] = $earliest($session['acted'], $acted);
            } else {
                $session['untimed'] = true;
            }

            $sessions[$visit['entry']] = $session;
        }

        foreach ($sessions as $entry => $session) {
            $opened = count($session['opened']);
            $shared = count($session['shared']);

            if ($opened > 0) {
                $parts = array_filter([
                    $session['views'] > $opened ? self::count($session['views'], 'view') : null,
                    $shared > 0 ? 'shared '.$shared : null,
                ]);

                $add(
                    $session['openedAt'],
                    'posts',
                    'eye',
                    'Opened '.self::count($opened, 'post'),
                    match (true) {
                        $parts !== [] => ucfirst(implode(' · ', $parts)).'.',
                        $opened === 1 => 'One view.',
                        default => 'One view each.',
                    },
                    self::sessionMeta($entry, array_keys($session['opened'])),
                );
            } elseif ($shared > 0) {
                $add($session['sharedAt'], 'posts', 'share-2', 'Shared '.self::count($shared, 'post'), 'Without opening '.($shared === 1 ? 'it' : 'them').'.',
                    self::sessionMeta($entry, array_keys($session['shared'])));
            }

            $voted = count($session['voted']);
            $commented = count($session['commented']);
            $did = array_keys(array_filter([
                'voted' => $voted > 0 || $session['votes'] > 0,
                'commented' => $commented > 0 || $session['comments'] > 0,
                'published '.self::count($session['made'], 'post') => $session['made'] > 0,
            ]));

            if ($did === []) {
                continue;
            }

            $parts = array_filter([
                $voted > 0 ? 'voted on '.self::count($voted, 'post') : null,
                $commented > 0 ? 'commented on '.self::count($commented, 'post') : null,
                // The member's own counts, where they say more than the posts voted and commented on.
                $session['votes'] > $voted ? self::count($session['votes'], 'vote') : null,
                $session['comments'] > $commented ? self::count($session['comments'], 'comment') : null,
                $session['made'] > 0 ? self::count($session['made'], 'post', 'posts').' published' : null,
            ]);
            [$at, $when] = $this->actedAt($entry, $session);

            $add(
                $at,
                'acted',
                match (true) {
                    $voted > 0 || $session['votes'] > 0 => 'thumbs-up',
                    $commented > 0 || $session['comments'] > 0 => 'message-square',
                    default => 'pencil',
                },
                ucfirst(match (count($did)) {
                    1 => $did[0],
                    default => implode(', ', array_slice($did, 0, -1)).' and '.$did[count($did) - 1],
                }),
                ucfirst(implode(' · ', $parts)).'.'.$when,
                self::sessionMeta($entry, array_keys($session['actedOn'])),
            );
        }
    }

    /**
     * When a session's member first voted, commented or made a post: the
     * moment its visits kept. Visits from before that moment was kept say
     * only how many: then the earliest it can have been, and a note that
     * says so — also when a later visit of the session kept its own, which
     * came after theirs. A guest can neither vote nor comment, so when the
     * member signed up or signed in during the session — between its start
     * and the next session of the same browser — no earlier than when this
     * browser was signed in: its first sign-in, or the sign-up when it was
     * this browser's, or, with no sign-in of its own, the sign-up anywhere,
     * before which there was no account; otherwise the first post of the
     * session, or its start.
     *
     * @param  array<string, mixed>  $session
     * @return array{0: CarbonImmutable, 1: string} the moment, and the note to add to the step
     */
    private function actedAt(int $entry, array $session): array
    {
        if ($session['acted'] !== null && ! $session['untimed']) {
            return [$session['acted'], ''];
        }

        [$at, $note] = $this->earliestActed($entry, $session);

        // Never later than a moment a visit of the session did keep.
        return $session['acted'] !== null && $session['acted'] < $at
            ? [$session['acted'], ' Earlier visits of the session didn’t keep when: drawn at the first moment one kept.']
            : [$at, $note];
    }

    /**
     * The earliest a session's member can have first acted, when its visits
     * didn't keep the moment (actedAt()).
     *
     * @param  array<string, mixed>  $session
     * @return array{0: CarbonImmutable, 1: string}
     */
    private function earliestActed(int $entry, array $session): array
    {
        $start = $this->sessionStart($entry, $session['at']);
        $hash = null;
        $next = null;
        // When the member came to be signed in during the session, and how.
        $bound = null;

        foreach ($this->visits as $visit) {
            if ($visit['entry'] === $entry) {
                $hash = $visit['hash'];

                if ($visit['login'] !== null && ($bound === null || $visit['login'] < $bound[0])) {
                    $bound = [$visit['login'], 'sign-in'];
                }
            }
        }

        foreach ($this->visits as $visit) {
            if ($visit['hash'] === $hash && $visit['entry'] !== $entry && $visit['at'] > $start && ($next === null || $visit['at'] < $next)) {
                $next = $visit['at'];
            }
        }

        $account = $this->account();
        $signedUp = $account['signedUp'] ?? null;

        // The browser that signed up was signed in from then on; another one only from its own sign-in, and none before the account was.
        if ($signedUp instanceof CarbonImmutable && $signedUp >= $start && ($next === null || $signedUp < $next)
            && ($bound === null || (($account['browser'] ?? null) === $hash && $signedUp < $bound[0]))) {
            $bound = [$signedUp, 'sign-up'];
        }

        if ($bound !== null && $bound[0] > $session['at']) {
            return [$bound[0], ' Visits this old didn’t keep when: drawn at the '.$bound[1].', since a guest can’t vote or comment.'];
        }

        return [$session['at'], $session['opened'] === [] && $session['actedOn'] === [] && $session['shared'] === []
            ? ' Visits this old didn’t keep when: drawn at the start of the session.'
            : ' Visits this old didn’t keep when: drawn at the first post of the session.'];
    }

    /** When a session began: the earliest of its visits read, or the moment given when none of them was. */
    private function sessionStart(int $entry, CarbonImmutable $otherwise): CarbonImmutable
    {
        $start = null;

        foreach ($this->visits as $visit) {
            if ($visit['entry'] === $entry && ($start === null || $visit['at'] < $start)) {
                $start = $visit['at'];
            }
        }

        return $start ?? $otherwise;
    }

    /**
     * A session's line's meta: the session, and the posts it names — five at most.
     *
     * @param  list<int>  $ids
     */
    private static function sessionMeta(int $entry, array $ids): string
    {
        return implode(' · ', array_filter([
            'session_'.$entry,
            $ids === [] ? null : implode(', ', array_map(fn (int $id): string => '#'.$id, array_slice($ids, 0, 5))).(count($ids) > 5 ? ' +'.(count($ids) - 5) : ''),
        ]));
    }

    /** The versions games gave, each attempt and how far it got, and what was pressed or left on the way. */
    private function gameSteps(callable $add): void
    {
        foreach ($this->journey['assignments'] as $assignment) {
            $period = $this->journey['periods'][$assignment['period']] ?? null;
            $version = $this->version($assignment['version']);
            $body = $period === null
                ? 'Assigned '.$version.'.'
                : 'Assigned '.$version.' in the period from '.$period['from']->format('d M').($period['scope'] === null ? '' : ' for '.$period['scope'])
                    .' ('.implode(' · ', array_map(fn (array $share): string => 'V'.$share['number'].' '.$share['percent'].'%', $period['shares'])).').';
            $ended = $assignment['ended'] ?? null;
            $add($assignment['at'], 'assigned', 'flask-conical', 'Assigned to '.$this->game($assignment['game']), $body.($ended === null
                ? ' The version stays the same when they come back.'
                : ' Kept until '.$ended->format('d M H:i').', when the version lost its share or could no longer be played.'), 'assignment_'.$assignment['id']);
        }

        foreach ($this->journey['attempts'] as $attempt) {
            $meta = 'attempt_'.$attempt['id'];
            $flow = $this->journey['versions'][$attempt['version']]['flow'] ?? null;
            $add($attempt['started'], 'started', 'gamepad-2', 'Started '.$this->game($attempt['game']), 'Attempt on '.$this->version($attempt['version']).($flow === null ? '' : ' with '.$flow).'.', $meta);

            if ($attempt['completed'] !== null) {
                $add($attempt['completed'], 'done', 'flag', 'Completed the game', $attempt['score'] === null ? 'Finished.' : 'Score '.$attempt['score'].' of '.$attempt['max'].'.', $meta);
            } else {
                $rounds = (int) ($this->journey['versions'][$attempt['version']]['rounds'] ?? 0);
                $add($attempt['last'], 'done', 'log-out', 'Didn’t finish', $attempt['rounds'].($rounds > 0 ? ' of '.$rounds : '').' rounds answered. Attempt not completed.', $meta);
            }

            if ($attempt['goal'] !== null) {
                $goal = $this->journey['versions'][$attempt['version']]['goal'] ?? null;
                $add($attempt['goal'], 'goal', 'target', 'Reached the goal', ($goal === null ? 'The goal of its flow' : $goal.': the goal of its flow').'.', $meta);
            }

            if ($attempt['outcome'] !== null && $attempt['outcomeAt'] !== null) {
                $account = $this->journey['user'] === null ? 'the account' : self::userId((int) $this->journey['user']);
                $add($attempt['outcomeAt'], 'claimed', 'link', $attempt['outcome'] === 'registered' ? 'Result claimed' : 'Result kept on sign-in',
                    $attempt['outcome'] === 'registered' ? $meta.' is linked to the new account, '.$account.'.' : $meta.' is linked to the account it signed in to.', $meta);
            }
        }

        foreach ($this->journey['events'] as $event) {
            $meta = 'attempt_'.$event['attempt'];

            if ($event['type'] === 'share_clicked') {
                $add($event['at'], 'pressed', 'share-2', 'Shared the result', match ($event['provider']) {
                    null, '' => 'Pressed a share button.',
                    'copy_link' => 'Copied the link to the result.',
                    'native' => 'Through the device’s own share sheet.',
                    default => 'Through '.ucfirst(str_replace('_', ' ', (string) $event['provider'])).'.',
                }, $meta);

                continue;
            }

            $add($event['at'], 'pressed', 'mouse-pointer-click', match ($event['provider']) {
                'login' => 'Clicked “Sign in”',
                'email' => 'Started signing up with email',
                'google', 'facebook' => 'Started signing up with '.ucfirst((string) $event['provider']),
                default => 'Clicked “Sign up”',
            }, 'Registration call to action on the result.', $meta);
        }

        foreach ($this->journey['emails'] as $email) {
            $add($email['at'], 'pressed', 'mail-check', 'Email captured', 'Stored by the game. The address isn’t shown in analytics.', 'attempt_'.$email['attempt']);
        }
    }

    /** The account's sign-up, confirmation and activation, and every later day it came back and did something. */
    private function accountSteps(callable $add): void
    {
        $attribution = $this->account();
        $activatedDay = $attribution === null ? null : $attribution['activated']?->toDateString();

        // The days are read only for the account whose steps belong: drawn even when its sign-up was never recorded.
        foreach ($this->journey['days'] as $day) {
            if ($day['number'] >= 1 && $day['day'] !== $activatedDay) {
                $add($day['at'], 'returned', 'repeat', 'Came back on day '.$day['number'], ucfirst(self::did($day)).'.');
            }
        }

        if ($attribution === null) {
            return;
        }

        $user = self::userId((int) $this->journey['user']);
        $credited = $attribution['visit'] === null ? null : $this->visit($attribution['visit']);
        $social = in_array($attribution['method'], ['google', 'facebook'], true);
        // Google and Facebook confirm the address with the sign-up itself: one step, not two of the same moment.
        $atOnce = $social && $attribution['confirmed'] !== null
            && abs($attribution['confirmed']->getTimestamp() - $attribution['signedUp']->getTimestamp()) <= self::AT_ONCE_SECONDS;
        $add($attribution['signedUp'], 'signup', 'user-plus', 'Signed up with '.self::method($attribution['method']),
            'Account '.$user.' created'.(AccountSignupPlace::tryFrom((string) $attribution['place']) === null ? '' : ' '.lcfirst(AccountSignupPlace::from((string) $attribution['place'])->label()))
            .($atOnce ? ' and confirmed at once by '.self::method($attribution['method']).': the new account counts from here' : '').'.'
            .($credited === null ? '' : ' Conversion touch: '.AnalyticsSource::label($credited['source'], $this->sourceNames).'.'), $user);

        if ($attribution['confirmed'] !== null && ! $atOnce) {
            $add($attribution['confirmed'], 'confirmed', 'circle-check', 'Confirmed the account', $social
                ? 'Confirmed by '.self::method($attribution['method']).': the new account counts from here.'
                : 'The email address was confirmed: the new account counts from here.');
        }

        $signedUpDay = $attribution['signedUp']->startOfDay();

        if ($attribution['activated'] !== null) {
            $day = null;

            foreach ($this->journey['days'] as $each) {
                if ($each['day'] === $activatedDay) {
                    $day = $each;
                }
            }

            $number = $day['number'] ?? (int) $signedUpDay->diffInDays($attribution['activated']->startOfDay());
            $did = $day === null ? 'did something' : self::did($day);
            $add($attribution['activated'], 'activated', 'badge-check', 'Came back on day '.$number.' and '.$did.' — activation rule met', 'The first vote, comment, post or game on one of the 7 days after signing up.', 'Within 7 days');
        }
    }

    /**
     * Attribution: how the browser first came and last came from somewhere
     * named, and — for an account — the visit and the game its sign-up is
     * credited to; for nobody signed up yet, that there is no conversion.
     *
     * @return list<array{label: string, value: string, note: string}>
     */
    private function touches(): array
    {
        $first = $this->first === null
            ? ['label' => 'First touch', 'value' => 'None recorded', 'note' => 'No visit of this '.($this->journey['kind'] === 'user' ? 'account' : 'browser').' was recorded']
            : ['label' => 'First touch', 'value' => $this->source($this->first), 'note' => $this->first['at']->format('d M').' · '.$this->basis($this->first)];
        $attribution = $this->account();
        // A touch is where a session came from: its first visit, whose referrer is the one that brought it.
        $starts = array_values(array_filter($this->visits, fn (array $visit): bool => $visit['entry'] === $visit['id']));
        $starts = $starts === [] ? $this->visits : $starts;

        if ($attribution === null) {
            $latest = $starts === [] ? null : $starts[count($starts) - 1];
            $other = $this->journey['user'] !== null && $this->journey['kind'] === 'visitor';

            return array_values(array_filter([
                $first,
                $latest === null || $latest['id'] === ($this->first['id'] ?? null) ? null : ['label' => 'Latest touch', 'value' => $this->source($latest), 'note' => $latest['at']->format('d M').' · '.$this->basis($latest)],
                match (true) {
                    $other => ['label' => 'Conversion touch', 'value' => 'In another browser', 'note' => self::userId((int) $this->journey['user']).' signed up elsewhere: its journey has the sign-up'],
                    $this->journey['user'] !== null => ['label' => 'Conversion touch', 'value' => 'Not recorded', 'note' => 'The account signed up before the site recorded what accounts are credited to'],
                    default => ['label' => 'Conversion touch', 'value' => 'None yet', 'note' => 'No account created'],
                },
            ]));
        }

        $credited = $attribution['visit'] === null ? null : $this->visit($attribution['visit']);
        $expired = $attribution['expired'] === null ? null : $this->visit($attribution['expired']);
        $closed = $expired === null ? null : 'a paid click whose window had closed, on '.$expired['at']->format('d M').' from '.AnalyticsSource::label($expired['source'], $this->sourceNames);
        $conversion = match (true) {
            $credited !== null => ['label' => 'Conversion touch', 'value' => $this->source($credited), 'note' => implode(' · ', array_filter([$credited['at']->format('d M'), $this->campaign($credited), $credited['creative'], $closed === null ? null : 'before it, '.$closed]))],
            $expired !== null => ['label' => 'Conversion touch', 'value' => 'None', 'note' => 'Only '.$closed.', so nothing is credited'],
            default => ['label' => 'Conversion touch', 'value' => 'None', 'note' => 'No visit of the last 7 days was recorded before signing up'],
        };

        $named = array_values(array_filter($starts, fn (array $visit): bool => ! in_array($visit['source'], [AnalyticsSource::DIRECT, AnalyticsSource::INTERNAL], true)));
        // Among the visits read, else — when only the latest were — the latest named start of all.
        $last = $named === [] ? ($this->journey['lastNamed'] ?? null) : $named[count($named) - 1];
        $cut = (bool) ($this->journey['cut'] ?? false);

        return [
            $first,
            $conversion,
            $last === null
                ? ['label' => 'Last non-direct touch', 'value' => 'None', 'note' => $cut ? 'None of the visits read came with tags or a referrer' : 'Every visit came with no tags and no referrer']
                : ['label' => 'Last non-direct touch', 'value' => $this->source($last), 'note' => $last['at']->format('d M').($last['at'] > $attribution['signedUp'] ? ' · after registration' : ' · '.$this->basis($last))],
            $this->credit($attribution, $credited),
        ];
    }

    /**
     * What the sign-up is credited to: the attempt finished before it — one
     * of the attempts read, else the one read for it alone — else the page
     * of the visit, else nothing.
     *
     * @param  array<string, mixed>  $attribution
     * @param  array<string, mixed>|null  $credited
     * @return array{label: string, value: string, note: string}
     */
    private function credit(array $attribution, ?array $credited): array
    {
        $attempt = null;

        foreach ($this->journey['attempts'] as $each) {
            if ($each['id'] === $attribution['attempt']) {
                $attempt = $each;
            }
        }

        $alone = $this->journey['creditedAttempt'] ?? null;
        $attempt ??= $alone !== null && $alone['id'] === $attribution['attempt'] ? $alone : null;

        if ($attempt !== null) {
            $flow = $this->journey['versions'][$attempt['version']]['flow'] ?? null;

            return ['label' => 'Registration credited to', 'value' => $this->game($attempt['game']).' · '.$this->version($attempt['version']), 'note' => implode(' · ', array_filter([$flow, 'attempt_'.$attempt['id']]))];
        }

        if ($credited !== null) {
            $page = $credited['game'] !== null ? $this->game($credited['game']) : ($credited['landing'] === null ? 'Another page' : LandingRoute::page($credited['landing']));

            return ['label' => 'Registration credited to', 'value' => $page, 'note' => 'The page of the credited visit · no game attempt was credited'];
        }

        return ['label' => 'Registration credited to', 'value' => 'Nothing', 'note' => 'No game attempt or visit was recorded before signing up'];
    }

    /**
     * Identity: the browser or browsers, the account, how many sessions and
     * attempts, and where the latest visit came from.
     *
     * @return list<array{icon: string, label: string, value: string}>
     */
    private function identity(): array
    {
        $browsers = $this->journey['browsers'];
        $ids = array_map(fn (string $hash): string => self::visitorId($hash), array_slice($browsers, 0, 2));
        $placed = array_values(array_filter($this->visits, fn (array $visit): bool => $visit['country'] !== null));
        $place = $placed === [] ? null : self::place($placed[count($placed) - 1]);

        return [
            count($browsers) > 1
                ? ['icon' => 'user-round', 'label' => 'Browsers', 'value' => implode(', ', $ids).(count($browsers) > 2 ? ' +'.(count($browsers) - 2) : '')]
                : ['icon' => 'user-round', 'label' => 'Visitor ID', 'value' => $ids[0] ?? 'None recorded'],
            ['icon' => 'user', 'label' => 'User ID', 'value' => $this->journey['user'] === null ? 'Not registered' : self::userId((int) $this->journey['user'])],
            ['icon' => 'layers', 'label' => 'Sessions · attempts', 'value' => $this->sessions().' · '.count($this->journey['attempts'])],
            ['icon' => 'map-pin', 'label' => 'Location', 'value' => $place === null ? 'Unknown' : $place.' · from IP'],
        ];
    }

    /**
     * A touch's source: the network and its medium, or Direct / Unknown and
     * Inside the site alone.
     *
     * @param  array<string, mixed>  $visit
     */
    private function source(array $visit): string
    {
        return in_array($visit['source'], [AnalyticsSource::DIRECT, AnalyticsSource::INTERNAL], true)
            ? AnalyticsSource::label($visit['source'])
            : AnalyticsSource::label($visit['source'], $this->sourceNames).' · '.AnalyticsSource::medium($visit['source']);
    }

    /**
     * What a touch was known by: the paid link and its campaign, the tags,
     * the referrer, or nothing at all.
     *
     * @param  array<string, mixed>  $visit
     */
    private function basis(array $visit): string
    {
        $key = $visit['source'];
        $host = $visit['referrer'] ?? $visit['origin'];

        return match (true) {
            AnalyticsSource::paidSourceId($key) !== null => implode(' · ', array_filter(['rg_src link', $this->campaign($visit), $visit['creative']])),
            $visit['tags'] !== [] => 'tagged'.(isset($visit['tags']['utm_campaign']) ? ' '.$visit['tags']['utm_campaign'] : ''),
            $key === AnalyticsSource::DIRECT => 'no tags and no referrer, so no source is assumed',
            $key === AnalyticsSource::SHARED => 'a result another player shared',
            $key === AnalyticsSource::INTERNAL => 'another page of the site',
            default => $host === null ? 'no tags' : 'inferred from referrer '.$host.', no tags',
        };
    }

    /**
     * A visit's campaign as it reads, if it came by one.
     *
     * @param  array<string, mixed>  $visit
     */
    private function campaign(array $visit): ?string
    {
        return $visit['campaign'] === null ? null : CampaignKey::label($visit['campaign'], $this->campaignNames);
    }

    /**
     * A visit by its id: among those read, or one the attribution names.
     *
     * @return array<string, mixed>|null
     */
    private function visit(int $id): ?array
    {
        foreach ([...$this->visits, ...($this->journey['credited'] ?? [])] as $visit) {
            if ($visit['id'] === $id) {
                return $visit;
            }
        }

        return null;
    }

    private function game(int $id): string
    {
        return $this->journey['games'][$id]['title'] ?? 'Game #'.$id;
    }

    private function version(int $id): string
    {
        $number = $this->journey['versions'][$id]['number'] ?? null;

        return $number === null ? 'a version' : 'V'.$number;
    }

    /**
     * Where a visit came from, by its IP: the city and the country, or the
     * country alone.
     *
     * @param  array<string, mixed>  $visit
     */
    private static function place(array $visit): ?string
    {
        if ($visit['country'] === null) {
            return null;
        }

        $country = Countries::name((string) $visit['country']);

        return $visit['city'] === null ? $country : $visit['city'].', '.$country;
    }

    /** How an account signed up: Email, Google or Facebook. */
    private static function method(?string $method): string
    {
        return match ($method) {
            'google' => 'Google',
            'facebook' => 'Facebook',
            default => 'email',
        };
    }

    /**
     * What a member did on a day: voted, commented, posted, played a game.
     *
     * @param  array<string, mixed>  $day
     */
    private static function did(array $day): string
    {
        $did = array_keys(array_filter(['voted' => $day['voted'], 'commented' => $day['commented'], 'posted' => $day['posted'], 'played a game' => $day['played']]));

        return match (count($did)) {
            0 => 'did something',
            1 => $did[0],
            default => implode(', ', array_slice($did, 0, -1)).' and '.$did[count($did) - 1],
        };
    }

    /** A count with its noun, one or many. */
    private static function count(int $count, string $one, ?string $many = null): string
    {
        return $count.' '.($count === 1 ? $one : ($many ?? $one.'s'));
    }
}
