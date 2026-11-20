<?php

use App\Support\Analytics\JourneyTimeline;
use Carbon\CarbonImmutable;

/*
 * A journey told from what was read: an ID read only when it is one; every
 * step in order — a session's arrival by where it came from, the page it
 * went on to, its posts in one line, the version a period gave with the
 * period's shares, the attempts and how far they got, the buttons pressed,
 * an email left, the sign-up, its confirmation, the activation on its day
 * and the later days it came back, even with no sign-up recorded — the
 * latest two hundred at most; Attribution for an account and for nobody
 * signed up yet; Identity; a member with more browsers than were read.
 */

/** The visitor hash of the browser most journeys here are of. */
const JOURNEY_BROWSER = '8f31c2ab51e04d77a0b1c2d3e4f5061728394a5b6c7d8e9f00112233445566ff';

/**
 * A visit as JourneyQuery reads it: Direct, on a phone, beginning its own
 * session, unless the attributes say otherwise.
 *
 * @return array<string, mixed>
 */
function journeyVisitRecord(int $id, string $at, array $attributes = []): array
{
    $moment = CarbonImmutable::parse($at, 'UTC');

    return [
        'id' => $id, 'entry' => $id, 'hash' => JOURNEY_BROWSER, 'at' => $moment, 'seen' => $moment, 'opened' => true,
        'source' => 'direct', 'campaign' => null, 'creative' => null, 'landing' => null, 'tags' => [], 'origin' => null,
        'path' => null, 'referrer' => null, 'device' => 'mobile', 'country' => null, 'city' => null, 'game' => null,
        'login' => null, 'votes' => 0, 'comments' => 0, 'posts' => 0, 'acted' => null,
        ...$attributes,
    ];
}

/**
 * The journey of the parts given — one browser's, of nobody signed up,
 * with nothing recorded but what they say.
 *
 * @param  array<string, mixed>  $parts
 */
function journeyOf(array $parts, array $sourceNames = [], array $campaignNames = []): array
{
    return (new JourneyTimeline([
        'kind' => 'visitor', 'browsers' => [JOURNEY_BROWSER], 'user' => null, 'account' => false,
        'visits' => [], 'first' => null, 'credited' => [], 'posts' => [], 'attempts' => [], 'assignments' => [], 'periods' => [],
        'events' => [], 'emails' => [], 'attribution' => null, 'days' => [], 'games' => [], 'versions' => [], 'cut' => false,
        ...$parts,
    ], $sourceNames, $campaignNames))->view();
}

/** @return array<string, mixed> an attempt as JourneyQuery reads it, finished unless the attributes say otherwise */
function journeyAttemptRecord(int $id, string $at, array $attributes = []): array
{
    $moment = CarbonImmutable::parse($at, 'UTC');

    return [
        'id' => $id, 'game' => 7, 'version' => 70, 'status' => 'completed', 'rounds' => 5, 'score' => 3, 'max' => 5,
        'started' => $moment, 'completed' => $moment->addMinute(), 'last' => $moment->addMinute(), 'goal' => null, 'outcome' => null, 'outcomeAt' => null,
        ...$attributes,
    ];
}

it('reads an ID only when it is one', function () {
    expect(JourneyTimeline::read(' Visitor_8F31C2AB '))->toBe(['kind' => 'visitor', 'value' => '8f31c2ab'])
        ->and(JourneyTimeline::read('visitor_8f31c2ab51e04d77'))->toBe(['kind' => 'visitor', 'value' => '8f31c2ab51e04d77'])
        ->and(JourneyTimeline::read('user_3814'))->toBe(['kind' => 'user', 'value' => 3814])
        // Fewer digits than an ID shows, digits that aren't hex, an account that can't be, an address or a name: none is one.
        ->and(JourneyTimeline::read('visitor_8f31c2'))->toBeNull()
        ->and(JourneyTimeline::read('visitor_8f31c2zz'))->toBeNull()
        ->and(JourneyTimeline::read('user_0'))->toBeNull()
        ->and(JourneyTimeline::read('user_12a'))->toBeNull()
        ->and(JourneyTimeline::read('ann@example.com'))->toBeNull()
        ->and(JourneyTimeline::read(['user_1']))->toBeNull()
        ->and(JourneyTimeline::visitorId(JOURNEY_BROWSER))->toBe('visitor_8f31c2ab')
        ->and(JourneyTimeline::visitorId(JOURNEY_BROWSER, JourneyTimeline::LONG_DIGITS))->toBe('visitor_8f31c2ab51e04d77')
        ->and(JourneyTimeline::userId(3814))->toBe('user_3814');
});

it('tells a member’s way from the first visit to the days they came back, with what the sign-up is credited to', function () {
    $view = (new JourneyTimeline([
        'kind' => 'user', 'browsers' => [JOURNEY_BROWSER], 'user' => 3814, 'account' => true,
        'visits' => [
            journeyVisitRecord(10, '2026-10-01 19:42:00', ['source' => 'reddit:organic', 'referrer' => 'old.reddit.com', 'landing' => 'games.play:7:70', 'game' => 7, 'country' => 'DE', 'city' => 'Hamburg']),
            journeyVisitRecord(20, '2026-10-03 18:10:00', ['source' => 'paid:4', 'campaign' => 'paid:9', 'creative' => 'video_03', 'path' => '/games/trust-your-taste', 'game' => 7, 'device' => 'desktop', 'country' => 'DE']),
            journeyVisitRecord(21, '2026-10-03 18:15:00', ['entry' => 20, 'source' => 'paid:4', 'campaign' => 'paid:9', 'path' => '/posts/12', 'votes' => 1, 'comments' => 1, 'acted' => CarbonImmutable::parse('2026-10-03 18:16:00', 'UTC')]),
            journeyVisitRecord(30, '2026-10-07 08:31:00', ['source' => 'email:email', 'tags' => ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'weekly-40'], 'login' => CarbonImmutable::parse('2026-10-07 08:32:00', 'UTC')]),
        ],
        'posts' => [
            ['visit' => 21, 'post' => 12, 'views' => 2, 'shared' => true, 'voted' => true, 'commented' => false, 'at' => CarbonImmutable::parse('2026-10-03 18:15:00', 'UTC')],
            ['visit' => 21, 'post' => 15, 'views' => 1, 'shared' => false, 'voted' => false, 'commented' => true, 'at' => CarbonImmutable::parse('2026-10-03 18:17:00', 'UTC')],
        ],
        'attempts' => [
            journeyAttemptRecord(5512, '2026-10-01 19:43:00'),
            journeyAttemptRecord(5893, '2026-10-03 18:11:00', ['score' => 4, 'goal' => CarbonImmutable::parse('2026-10-03 18:12:00', 'UTC'), 'outcome' => 'registered', 'outcomeAt' => CarbonImmutable::parse('2026-10-03 18:13:00', 'UTC')]),
        ],
        'assignments' => [['id' => 931, 'game' => 7, 'version' => 70, 'period' => 3, 'at' => CarbonImmutable::parse('2026-10-03 18:10:00', 'UTC')]],
        'periods' => [3 => ['from' => CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'), 'scope' => null, 'shares' => [['number' => 7, 'percent' => 80], ['number' => 8, 'percent' => 20]]]],
        'events' => [['attempt' => 5893, 'type' => 'registration_cta_clicked', 'provider' => 'register', 'at' => CarbonImmutable::parse('2026-10-03 18:12:30', 'UTC')]],
        'emails' => [],
        'attribution' => [
            'signedUp' => CarbonImmutable::parse('2026-10-03 18:13:00', 'UTC'), 'confirmed' => CarbonImmutable::parse('2026-10-03 18:13:00', 'UTC'),
            'method' => 'google', 'place' => 'game', 'visit' => 20, 'attempt' => 5893, 'expired' => null,
            'activated' => CarbonImmutable::parse('2026-10-06 10:00:00', 'UTC'),
        ],
        'days' => [
            ['day' => '2026-10-07', 'number' => 4, 'voted' => true, 'commented' => true, 'posted' => false, 'played' => false, 'at' => CarbonImmutable::parse('2026-10-07 08:40:00', 'UTC')],
            ['day' => '2026-10-06', 'number' => 3, 'voted' => true, 'commented' => false, 'posted' => false, 'played' => false, 'at' => CarbonImmutable::parse('2026-10-06 10:00:00', 'UTC')],
        ],
        'first' => null, 'credited' => [],
        'games' => [7 => ['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']],
        'versions' => [70 => ['number' => 7, 'flow' => 'Result First', 'goal' => 'New account confirmed', 'rounds' => 5]],
        'cut' => false,
    ], [4 => 'Instagram tests'], [9 => 'Versus October Launch']))->view();

    expect($view['label'])->toBe('visitor_8f31c2ab · user_3814')
        ->and($view['status'])->toBe('Registered and activated · 3 sessions · 2 attempts')
        ->and($view['cut'])->toBeFalse()
        ->and(array_map(fn (array $entry): string => $entry['time'].' '.$entry['title'], $view['entries']))->toBe([
            '01 Oct 19:42 Arrived from Reddit',
            '01 Oct 19:43 Started Trust Your Taste',
            '01 Oct 19:44 Completed the game',
            '03 Oct 18:10 Returned from Instagram tests',
            '03 Oct 18:10 Assigned to Trust Your Taste',
            '03 Oct 18:11 Started Trust Your Taste',
            '03 Oct 18:12 Completed the game',
            '03 Oct 18:12 Reached the goal',
            '03 Oct 18:12 Clicked “Sign up”',
            '03 Oct 18:13 Signed up with Google',
            '03 Oct 18:13 Result claimed',
            '03 Oct 18:15 Went on to /posts/12',
            '03 Oct 18:15 Opened 2 posts',
            '03 Oct 18:16 Voted and commented',
            '06 Oct 10:00 Came back on day 3 and voted — activation rule met',
            '07 Oct 08:31 Returned from Email',
            '07 Oct 08:32 Signed in',
            '07 Oct 08:40 Came back on day 4',
        ]);

    $entries = collect($view['entries'])->keyBy('title');

    expect($entries['Arrived from Reddit']['body'])->toBe('No campaign tags. Source inferred from the referrer old.reddit.com.')
        // Its page by its landing, while the details that kept the path are gone; its place by its IP.
        ->and($entries['Arrived from Reddit']['meta'])->toBe('session_10 · /games/trust-your-taste · Mobile · Hamburg, Germany')
        ->and($entries['Returned from Instagram tests']['body'])->toBe('Paid click · Versus October Launch · video_03. The Reddit first touch is kept.')
        ->and($entries['Assigned to Trust Your Taste']['body'])->toBe('Assigned V7 in the period from 02 Oct (V7 80% · V8 20%). The version stays the same when they come back.')
        ->and($entries['Assigned to Trust Your Taste']['meta'])->toBe('assignment_931')
        ->and($entries['Reached the goal']['body'])->toBe('New account confirmed: the goal of its flow.')
        // Google confirmed the address with the sign-up: one step, not a second of the same moment.
        ->and($entries['Signed up with Google']['body'])->toBe('Account user_3814 created in the game and confirmed at once by Google: the new account counts from here. Conversion touch: Instagram tests.')
        ->and($entries->has('Confirmed the account'))->toBeFalse()
        ->and($entries['Result claimed']['body'])->toBe('attempt_5893 is linked to the new account, user_3814.')
        // The session's posts in one line, and what the member did on them in another, when they first did.
        ->and($entries['Opened 2 posts']['body'])->toBe('3 views · shared 1.')
        ->and($entries['Opened 2 posts']['meta'])->toBe('session_20 · #12, #15')
        ->and($entries['Voted and commented']['body'])->toBe('Voted on 1 post · commented on 1 post.')
        ->and($entries['Voted and commented']['meta'])->toBe('session_20 · #12, #15')
        ->and($entries['Returned from Email']['body'])->toBe('Campaign tags: utm_source=newsletter · utm_medium=email · utm_campaign=weekly-40. The Reddit first touch is kept.')
        ->and($entries['Came back on day 4']['body'])->toBe('Voted and commented.')
        ->and($view['touches'])->toBe([
            ['label' => 'First touch', 'value' => 'Reddit · organic_social', 'note' => '01 Oct · inferred from referrer old.reddit.com, no tags'],
            ['label' => 'Conversion touch', 'value' => 'Instagram tests · paid_ads', 'note' => '03 Oct · Versus October Launch · video_03'],
            ['label' => 'Last non-direct touch', 'value' => 'Email · email', 'note' => '07 Oct · after registration'],
            ['label' => 'Registration credited to', 'value' => 'Trust Your Taste · V7', 'note' => 'Result First · attempt_5893'],
        ])
        ->and($view['identity'])->toBe([
            ['icon' => 'user-round', 'label' => 'Visitor ID', 'value' => 'visitor_8f31c2ab'],
            ['icon' => 'user', 'label' => 'User ID', 'value' => 'user_3814'],
            ['icon' => 'layers', 'label' => 'Sessions · attempts', 'value' => '3 · 2'],
            ['icon' => 'map-pin', 'label' => 'Location', 'value' => 'Germany · from IP'],
        ])
        ->and($view['note'])->toStartWith('Every browser user_3814 signed up or signed in with, together');
});

it('says of nobody signed up yet where they first and last came from, and that nothing converted', function () {
    $view = journeyOf([
        'visits' => [
            journeyVisitRecord(1, '2026-10-05 12:03:00', ['landing' => 'games.play:9', 'game' => 9]),
            journeyVisitRecord(2, '2026-10-08 21:15:00', ['source' => 'shared', 'landing' => 'games.result:7', 'game' => 7, 'country' => 'BG', 'city' => 'Sofia']),
            // Gone on inside the session, the site itself its referrer: a page, never a touch.
            journeyVisitRecord(3, '2026-10-08 21:30:00', ['entry' => 2, 'source' => 'shared', 'landing' => 'games.result:7', 'path' => '/posts/9', 'referrer' => 'rateguru.test']),
        ],
        'attempts' => [
            journeyAttemptRecord(6120, '2026-10-05 12:04:00', ['game' => 9, 'version' => 90, 'status' => 'started', 'rounds' => 3, 'score' => null, 'completed' => null]),
            journeyAttemptRecord(6455, '2026-10-08 21:16:00', ['score' => 2]),
        ],
        'events' => [['attempt' => 6455, 'type' => 'share_clicked', 'provider' => 'copy_link', 'at' => CarbonImmutable::parse('2026-10-08 21:18:00', 'UTC')]],
        'emails' => [['attempt' => 6455, 'at' => CarbonImmutable::parse('2026-10-08 21:17:00', 'UTC')]],
        'games' => [7 => ['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste'], 9 => ['title' => 'Swipe Challenge', 'slug' => 'swipe-challenge']],
        'versions' => [70 => ['number' => 7, 'flow' => null, 'goal' => null, 'rounds' => 5], 90 => ['number' => 3, 'flow' => null, 'goal' => null, 'rounds' => 5]],
    ]);

    $entries = collect($view['entries'])->keyBy('title');

    expect($view['label'])->toBe('visitor_8f31c2ab · not registered')
        ->and($view['status'])->toBe('Anonymous · email captured · 2 sessions · 2 attempts')
        ->and($entries['Arrived with no attribution']['body'])->toBe('No tags and no referrer. Counted as Direct / Unknown.')
        ->and($entries['Arrived with no attribution']['meta'])->toBe('session_1 · /games/swipe-challenge · Mobile')
        ->and($entries['Didn’t finish']['body'])->toBe('3 of 5 rounds answered. Attempt not completed.')
        ->and($entries['Returned from a shared result']['meta'])->toBe('session_2 · /games/trust-your-taste · shared result · Mobile · Sofia, Bulgaria')
        ->and($entries['Email captured']['body'])->toBe('Stored by the game. The address isn’t shown in analytics.')
        ->and($entries['Shared the result']['body'])->toBe('Copied the link to the result.')
        ->and($entries['Went on to /posts/9']['meta'])->toBe('session_2 · /posts/9 · Mobile')
        ->and($view['touches'])->toBe([
            ['label' => 'First touch', 'value' => 'Direct / Unknown', 'note' => '05 Oct · no tags and no referrer, so no source is assumed'],
            ['label' => 'Latest touch', 'value' => 'Shared results · referral', 'note' => '08 Oct · a result another player shared'],
            ['label' => 'Conversion touch', 'value' => 'None yet', 'note' => 'No account created'],
        ])
        ->and(collect($view['identity'])->pluck('value', 'label')->all())->toBe([
            'Visitor ID' => 'visitor_8f31c2ab', 'User ID' => 'Not registered', 'Sessions · attempts' => '2 · 2', 'Location' => 'Sofia, Bulgaria · from IP',
        ]);
});

it('credits nothing to a paid click whose window had closed, and says so', function () {
    $view = (new JourneyTimeline([
        'kind' => 'user', 'browsers' => [JOURNEY_BROWSER], 'user' => 12, 'account' => true,
        'visits' => [journeyVisitRecord(1, '2026-10-01 10:00:00', ['source' => 'paid:4'])],
        'first' => null, 'credited' => [], 'posts' => [], 'attempts' => [], 'assignments' => [], 'periods' => [], 'events' => [], 'emails' => [],
        'attribution' => ['signedUp' => CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC'), 'confirmed' => null, 'method' => 'email', 'place' => 'feed', 'visit' => null, 'attempt' => null, 'expired' => 1, 'activated' => null],
        'days' => [], 'games' => [], 'versions' => [], 'cut' => false,
    ], [4 => 'ExoClick tests']))->view();

    expect($view['status'])->toBe('Signed up · not confirmed · 1 session · 0 attempts')
        ->and(collect($view['entries'])->pluck('title')->all())->toBe(['Arrived from ExoClick tests', 'Signed up with email'])
        ->and($view['touches'][1])->toBe(['label' => 'Conversion touch', 'value' => 'None', 'note' => 'Only a paid click whose window had closed, on 01 Oct from ExoClick tests, so nothing is credited'])
        ->and($view['touches'][3])->toBe(['label' => 'Registration credited to', 'value' => 'Nothing', 'note' => 'No game attempt or visit was recorded before signing up']);
});

it('keeps an account’s steps to its own journey when a browser only signed in to it', function () {
    $view = journeyOf(['user' => 44, 'visits' => [journeyVisitRecord(1, '2026-10-01 10:00:00', ['login' => CarbonImmutable::parse('2026-10-01 10:05:00', 'UTC')])]]);

    expect($view['label'])->toBe('visitor_8f31c2ab · user_44')
        ->and($view['status'])->toBe('Signed in as user_44 · 1 session · 0 attempts')
        ->and(collect($view['entries'])->pluck('title')->all())->toBe(['Arrived with no attribution', 'Signed in'])
        ->and($view['touches'][1])->toBe(['label' => 'Conversion touch', 'value' => 'In another browser', 'note' => 'user_44 signed up elsewhere: its journey has the sign-up']);
});

it('shows the latest two hundred steps of a longer journey, and says the earlier aren’t shown', function () {
    $visits = array_map(fn (int $n): array => journeyVisitRecord($n, CarbonImmutable::parse('2025-12-25 10:00:00', 'UTC')->addHours($n)->toDateTimeString()), range(1, 250));
    $view = journeyOf(['visits' => $visits]);

    expect($view['entries'])->toHaveCount(JourneyTimeline::MOST_STEPS)
        ->and($view['cut'])->toBeTrue()
        // Steps of two years carry the year.
        ->and($view['entries'][0]['time'])->toBe('27 Dec 2025 13:00')
        ->and($view['entries'][199]['time'])->toBe('04 Jan 2026 20:00')
        ->and($view['entries'][0]['title'])->toBe('Returned with no attribution');
});

it('counts a session’s votes and comments once: by the posts, and by the member’s own count only where it says more', function () {
    $view = journeyOf([
        'visits' => [
            journeyVisitRecord(1, '2026-10-01 10:00:00', ['votes' => 1, 'comments' => 1, 'acted' => CarbonImmutable::parse('2026-10-01 10:02:00', 'UTC')]),
            journeyVisitRecord(2, '2026-10-02 10:00:00', ['votes' => 3, 'acted' => CarbonImmutable::parse('2026-10-02 10:02:00', 'UTC')]),
        ],
        'posts' => [
            ['visit' => 1, 'post' => 12, 'views' => 1, 'shared' => false, 'voted' => true, 'commented' => true, 'at' => CarbonImmutable::parse('2026-10-01 10:01:00', 'UTC')],
            ['visit' => 2, 'post' => 15, 'views' => 1, 'shared' => false, 'voted' => true, 'commented' => false, 'at' => CarbonImmutable::parse('2026-10-02 10:01:00', 'UTC')],
        ],
    ]);

    expect(array_map(fn (array $entry): string => $entry['time'].' '.$entry['title'].': '.$entry['body'], array_slice($view['entries'], 1, 2)))->toBe([
        '01 Oct 10:01 Opened 1 post: One view.',
        '01 Oct 10:02 Voted and commented: Voted on 1 post · commented on 1 post.',
    ])
        // Three answers on one post: the post once, and the votes.
        ->and(collect($view['entries'])->firstWhere('title', 'Voted')['body'])->toBe('Voted on 1 post · 3 votes.');
});

it('draws what a guest did on a post after the sign-up it took, when the visit didn’t keep the moment', function () {
    // A paid click, a game, the post it sent them to; voting and commenting there took an account: Google, at once.
    $view = (new JourneyTimeline([
        'kind' => 'user', 'browsers' => [JOURNEY_BROWSER], 'user' => 490, 'account' => true,
        'visits' => [journeyVisitRecord(1725, '2026-11-20 04:06:00', ['source' => 'paid:4', 'game' => 7, 'votes' => 2, 'comments' => 1])],
        'posts' => [['visit' => 1725, 'post' => 9, 'views' => 6, 'shared' => false, 'voted' => true, 'commented' => true, 'at' => CarbonImmutable::parse('2026-11-20 04:09:30', 'UTC')]],
        'attempts' => [journeyAttemptRecord(64, '2026-11-20 04:08:00', ['score' => 5, 'goal' => CarbonImmutable::parse('2026-11-20 04:10:12', 'UTC'), 'outcome' => 'registered', 'outcomeAt' => CarbonImmutable::parse('2026-11-20 04:10:12', 'UTC')])],
        'assignments' => [], 'periods' => [], 'events' => [], 'emails' => [], 'days' => [], 'first' => null, 'credited' => [], 'cut' => false,
        'attribution' => [
            'signedUp' => CarbonImmutable::parse('2026-11-20 04:10:12', 'UTC'), 'confirmed' => CarbonImmutable::parse('2026-11-20 04:10:12', 'UTC'),
            'method' => 'google', 'place' => 'post', 'visit' => 1725, 'attempt' => 64, 'expired' => null, 'activated' => null,
        ],
        'games' => [7 => ['title' => 'Eagle Eye', 'slug' => 'eagle-eye']],
        'versions' => [70 => ['number' => 2, 'flow' => 'Result First', 'goal' => 'New account confirmed', 'rounds' => 5]],
    ], [4 => 'PopAds']))->view();

    expect(array_map(fn (array $entry): string => $entry['time'].' '.$entry['title'], $view['entries']))->toBe([
        '20 Nov 04:06 Arrived from PopAds',
        '20 Nov 04:08 Started Eagle Eye',
        '20 Nov 04:09 Completed the game',
        '20 Nov 04:09 Opened 1 post',
        '20 Nov 04:10 Signed up with Google',
        '20 Nov 04:10 Result claimed',
        '20 Nov 04:10 Voted and commented',
        '20 Nov 04:10 Reached the goal',
    ]);

    $entries = collect($view['entries'])->keyBy('title');

    expect($entries['Opened 1 post']['body'])->toBe('6 views.')
        ->and($entries['Voted and commented']['body'])->toBe('Voted on 1 post · commented on 1 post · 2 votes. Visits this old didn’t keep when: drawn at the sign-up, since a guest can’t vote or comment.')
        ->and($entries['Voted and commented']['meta'])->toBe('session_1725 · #9')
        ->and($entries['Signed up with Google']['body'])->toBe('Account user_490 created on a post and confirmed at once by Google: the new account counts from here. Conversion touch: PopAds.');
});

it('draws a member’s votes of a session no account was signed up in no later than the session allows', function () {
    $view = journeyOf([
        'visits' => [
            // A member signed in from the start, and a session with no post opened: its start, as the earliest it can have been.
            journeyVisitRecord(1, '2026-10-01 10:00:00', ['votes' => 1]),
            journeyVisitRecord(2, '2026-10-02 10:00:00', ['comments' => 2, 'posts' => 1]),
        ],
        'posts' => [['visit' => 1, 'post' => 12, 'views' => 1, 'shared' => false, 'voted' => true, 'commented' => false, 'at' => CarbonImmutable::parse('2026-10-01 10:05:00', 'UTC')]],
    ]);

    expect(array_map(fn (array $entry): string => $entry['time'].' '.$entry['title'].': '.$entry['body'], array_values(array_filter($view['entries'], fn (array $entry): bool => $entry['icon'] !== 'eye' && ! str_starts_with($entry['title'], 'Arrived') && ! str_starts_with($entry['title'], 'Returned')))))->toBe([
        '01 Oct 10:05 Voted: Voted on 1 post. Visits this old didn’t keep when: drawn with the first post the session opened.',
        '02 Oct 10:00 Commented and published 1 post: 2 comments · 1 post published. Visits this old didn’t keep when: drawn at the start of the session.',
    ]);
});

it('keeps a confirmation of its own when Google or Facebook confirmed the account later, and one by email always', function () {
    $account = fn (string $method, string $confirmed): array => journeyOf([
        'kind' => 'user', 'user' => 12, 'account' => true,
        'attribution' => ['signedUp' => CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'), 'confirmed' => CarbonImmutable::parse($confirmed, 'UTC'), 'method' => $method, 'place' => 'feed', 'visit' => null, 'attempt' => null, 'expired' => null, 'activated' => null],
    ]);

    expect(collect($account('facebook', '2026-10-02 10:00:59')['entries'])->pluck('title')->all())->toBe(['Signed up with Facebook'])
        ->and(collect($account('google', '2026-10-02 10:05:00')['entries'])->pluck('body', 'title')->all())->toBe([
            'Signed up with Google' => 'Account user_12 created in the feed.',
            'Confirmed the account' => 'Confirmed by Google: the new account counts from here.',
        ])
        ->and(collect($account('email', '2026-10-02 10:00:00')['entries'])->pluck('body', 'title')->all())->toBe([
            'Signed up with email' => 'Account user_12 created in the feed.',
            'Confirmed the account' => 'The email address was confirmed: the new account counts from here.',
        ]);
});

it('counts a post once in a session however many of its visits shared, voted and commented on it', function () {
    $row = fn (int $visit, string $at): array => ['visit' => $visit, 'post' => 12, 'views' => 1, 'shared' => true, 'voted' => true, 'commented' => true, 'at' => CarbonImmutable::parse($at, 'UTC')];
    $view = journeyOf([
        'visits' => [
            journeyVisitRecord(1, '2026-10-01 10:00:00'),
            journeyVisitRecord(2, '2026-10-01 10:05:00', ['entry' => 1]),
        ],
        'posts' => [$row(1, '2026-10-01 10:01:00'), $row(2, '2026-10-01 10:06:00')],
    ]);

    expect(collect($view['entries'])->firstWhere('title', 'Opened 1 post')['body'])->toBe('2 views · shared 1.')
        ->and(collect($view['entries'])->firstWhere('title', 'Voted and commented')['body'])->toStartWith('Voted on 1 post · commented on 1 post.');
});

it('names the last non-direct touch of a longer journey from before the visits read, and says when only those were read', function () {
    $account = [
        'kind' => 'user', 'user' => 12, 'account' => true, 'cut' => true,
        'visits' => [journeyVisitRecord(300, '2026-10-20 10:00:00'), journeyVisitRecord(301, '2026-10-21 10:00:00')],
        'attribution' => ['signedUp' => CarbonImmutable::parse('2026-10-02 10:00:00', 'UTC'), 'confirmed' => null, 'method' => 'email', 'place' => 'feed', 'visit' => null, 'attempt' => null, 'expired' => null, 'activated' => null],
    ];
    $reddit = journeyVisitRecord(1, '2026-10-01 10:00:00', ['source' => 'reddit:organic', 'referrer' => 'old.reddit.com']);

    expect(journeyOf([...$account, 'lastNamed' => $reddit])['touches'][2])->toBe(['label' => 'Last non-direct touch', 'value' => 'Reddit · organic_social', 'note' => '01 Oct · inferred from referrer old.reddit.com, no tags'])
        // Nothing named among the visits read: no more said of those that weren't.
        ->and(journeyOf($account)['touches'][2])->toBe(['label' => 'Last non-direct touch', 'value' => 'None', 'note' => 'None of the visits read came with tags or a referrer'])
        ->and(journeyOf([...$account, 'cut' => false])['touches'][2])->toBe(['label' => 'Last non-direct touch', 'value' => 'None', 'note' => 'Every visit came with no tags and no referrer']);
});

it('says a version stays the same only while its assignment is kept', function () {
    $view = journeyOf([
        'assignments' => [
            ['id' => 1, 'game' => 7, 'version' => 70, 'period' => 3, 'at' => CarbonImmutable::parse('2026-10-01 10:00:00', 'UTC'), 'ended' => CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC')],
            ['id' => 2, 'game' => 7, 'version' => 80, 'period' => 4, 'at' => CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC'), 'ended' => null],
        ],
        'games' => [7 => ['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']],
        'versions' => [70 => ['number' => 7, 'flow' => null, 'goal' => null, 'rounds' => 5], 80 => ['number' => 8, 'flow' => null, 'goal' => null, 'rounds' => 5]],
    ]);

    expect(array_column($view['entries'], 'body'))->toBe([
        'Assigned V7. Kept until 05 Oct 08:00, when the version lost its share or could no longer be played.',
        'Assigned V8. The version stays the same when they come back.',
    ]);
});

it('draws the days a member came back even when their sign-up was never recorded', function () {
    $view = journeyOf([
        'kind' => 'user', 'user' => 42, 'account' => true, 'attribution' => null,
        'days' => [['day' => '2026-10-13', 'number' => 12, 'voted' => true, 'commented' => false, 'posted' => false, 'played' => false, 'at' => CarbonImmutable::parse('2026-10-13 09:00:00', 'UTC')]],
    ]);

    expect($view['status'])->toBe('Member · 0 sessions · 0 attempts')
        ->and(array_map(fn (array $entry): array => [$entry['title'], $entry['body']], $view['entries']))->toBe([['Came back on day 12', 'Voted.']]);
});

it('credits a sign-up with no attempt before it to the page of its visit, in a whole sentence', function () {
    $view = journeyOf([
        'kind' => 'user', 'user' => 12, 'account' => true,
        'visits' => [journeyVisitRecord(1, '2026-10-01 10:00:00', ['source' => 'google:organic', 'game' => 7])],
        'attribution' => ['signedUp' => CarbonImmutable::parse('2026-10-01 10:05:00', 'UTC'), 'confirmed' => null, 'method' => 'email', 'place' => 'feed', 'visit' => 1, 'attempt' => null, 'expired' => null, 'activated' => null],
        'games' => [7 => ['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']],
    ]);

    expect($view['touches'][3])->toBe(['label' => 'Registration credited to', 'value' => 'Trust Your Taste', 'note' => 'The page of the credited visit · no game attempt was credited']);
});

it('credits a sign-up to its attempt though that attempt is older than the latest ones read', function () {
    $view = journeyOf([
        'kind' => 'user', 'user' => 12, 'account' => true, 'cut' => true,
        'attempts' => [journeyAttemptRecord(9001, '2026-10-09 10:00:00', ['game' => 9, 'version' => 90])],
        'creditedAttempt' => journeyAttemptRecord(5893, '2026-10-01 10:01:00'),
        'attribution' => ['signedUp' => CarbonImmutable::parse('2026-10-01 10:05:00', 'UTC'), 'confirmed' => null, 'method' => 'email', 'place' => 'game', 'visit' => null, 'attempt' => 5893, 'expired' => null, 'activated' => null],
        'games' => [7 => ['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste'], 9 => ['title' => 'Swipe Challenge', 'slug' => 'swipe-challenge']],
        'versions' => [70 => ['number' => 7, 'flow' => 'Result First', 'goal' => null, 'rounds' => 5], 90 => ['number' => 3, 'flow' => null, 'goal' => null, 'rounds' => 5]],
    ]);

    expect($view['touches'][3])->toBe(['label' => 'Registration credited to', 'value' => 'Trust Your Taste · V7', 'note' => 'Result First · attempt_5893'])
        // Credited, not one of the latest steps: neither drawn nor counted.
        ->and(collect($view['identity'])->firstWhere('label', 'Sessions · attempts')['value'])->toBe('0 · 1');
});

it('names a member by the browsers read, and says they signed in with more', function () {
    $view = journeyOf(['kind' => 'user', 'user' => 42, 'account' => true, 'more' => true, 'browsers' => [JOURNEY_BROWSER, str_repeat('a', 64)]]);

    expect($view['label'])->toBe('user_42 · more than 2 browsers')
        ->and($view['note'])->toStartWith('The latest '.JourneyTimeline::MOST_BROWSERS.' browsers user_42 signed in with, and the one it signed up in, together')
        ->and($view['note'])->toContain('It signed in with more, which aren’t read.');
});
