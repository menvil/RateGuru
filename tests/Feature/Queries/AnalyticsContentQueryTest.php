<?php

use App\Actions\Analytics\RecordVisitPostAction;
use App\Enums\TrafficDevice;
use App\Enums\UserActivity;
use App\Models\Post;
use App\Queries\Analytics\AnalyticsContentQuery;
use Carbon\CarbonImmutable;

/*
 * The posts of Analytics → Site engagement, read from the rows each visit
 * keeps of the posts it opened beside the visits the filter admits: the
 * views, the browsers that opened a post once each, of them those who voted
 * on one they opened, and the shares; the same per source; the posts opened
 * most of the days and the device alone, a post only voted on from the feed
 * left out; the accounts whose session — every visit sharing the entry of
 * the visit an account is credited to — opened each post first, and those
 * whose session opened none; and the posts themselves, a deleted one too.
 * The same on every database engine.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
});

/** @return AnalyticsContentQuery read both ways, each call compared (readBothWays()) */
function contentQuery(): object
{
    return readBothWays(AnalyticsContentQuery::class);
}

it('counts the views, each browser that opened a post once, those who voted on one they opened, and the shares of the visits the filter admits', function () {
    $ann = analyticsVisit('ann', ['source_key' => 'reddit:organic']);
    $annAgain = analyticsVisit('ann', ['source_key' => 'reddit:organic', 'network_day' => '2026-11-16']);
    $bob = analyticsVisit('bob', ['source_key' => 'google:organic', 'device' => TrafficDevice::Desktop]);
    $cat = analyticsVisit('cat', ['source_key' => 'reddit:organic']);

    analyticsVisitPost($ann, 1, ['views' => 3, 'voted' => true, 'shared_at' => '2026-11-15 10:05:00']);
    analyticsVisitPost($ann, 2, ['views' => 1]);
    analyticsVisitPost($annAgain, 1, ['views' => 2, 'shared_at' => '2026-11-16 10:05:00']);
    analyticsVisitPost($bob, 1, ['views' => 4]);
    // Voted on from the feed, never opened: a voter, but no viewer, so not one who voted on what they opened.
    analyticsVisitPost($cat, 3, ['views' => 0, 'voted' => true]);
    // A page that never ran, and a day outside the period, count nothing.
    analyticsVisitPost(analyticsVisit('dan', ['opened_at' => null]), 1, ['views' => 5]);
    analyticsVisitPost(analyticsVisit('eve', ['network_day' => '2026-11-10']), 1, ['views' => 5]);

    expect(contentQuery()->figures(analyticsFilter()))->toBe(['views' => 10, 'viewers' => 2, 'voters' => 1, 'shares' => 2])
        ->and(contentQuery()->figures(analyticsFilter(device: TrafficDevice::Desktop)))->toBe(['views' => 4, 'viewers' => 1, 'voters' => 0, 'shares' => 0])
        ->and(contentQuery()->figures(analyticsFilter(source: 'reddit:organic')))->toBe(['views' => 6, 'viewers' => 1, 'voters' => 1, 'shares' => 2])
        ->and(contentQuery()->figures(analyticsFilter('yesterday')))->toBe(['views' => 0, 'viewers' => 0, 'voters' => 0, 'shares' => 0])
        ->and(contentQuery()->bySource(analyticsFilter(), ['reddit:organic', 'google:organic', 'email:email']))->toBe([
            'google:organic' => ['views' => 4, 'viewers' => 1, 'voters' => 0, 'shares' => 0],
            'reddit:organic' => ['views' => 6, 'viewers' => 1, 'voters' => 1, 'shares' => 2],
        ])
        ->and(contentQuery()->bySource(analyticsFilter(), []))->toBe([]);
});

it('lists the posts opened most of the days and the device, whatever source brought the visits, a post never opened left out', function () {
    $reddit = analyticsVisit('ann', ['source_key' => 'reddit:organic']);
    $google = analyticsVisit('bob', ['source_key' => 'google:organic']);
    $google2 = analyticsVisit('cat', ['source_key' => 'google:organic']);
    $desktop = analyticsVisit('dan', ['device' => TrafficDevice::Desktop]);

    analyticsVisitPost($reddit, 7, ['views' => 2, 'voted' => true, 'commented' => true]);
    analyticsVisitPost($google, 7, ['views' => 1]);
    analyticsVisitPost($google2, 7, ['views' => 0, 'voted' => true]);
    analyticsVisitPost($google, 5, ['views' => 3, 'commented' => true]);
    analyticsVisitPost($reddit, 9, ['views' => 3]);
    analyticsVisitPost($desktop, 4, ['views' => 9]);
    analyticsVisitPost($google2, 8, ['views' => 0, 'voted' => true]);

    $period = analyticsFilter()->period;

    expect(contentQuery()->topPosts($period, null, 10))->toBe([
        ['postId' => 4, 'views' => 9, 'viewers' => 1, 'engaged' => 0, 'votes' => 0, 'voters' => 0, 'comments' => 0],
        // The same views, the lower id first.
        ['postId' => 5, 'views' => 3, 'viewers' => 1, 'engaged' => 1, 'votes' => 0, 'voters' => 0, 'comments' => 1],
        // Voted on from the feed by one who never opened it: a vote and a voter, but no viewer engaged.
        ['postId' => 7, 'views' => 3, 'viewers' => 2, 'engaged' => 1, 'votes' => 2, 'voters' => 2, 'comments' => 1],
        ['postId' => 9, 'views' => 3, 'viewers' => 1, 'engaged' => 0, 'votes' => 0, 'voters' => 0, 'comments' => 0],
    ])
        ->and(array_column(contentQuery()->topPosts($period, TrafficDevice::Mobile, 2), 'postId'))->toBe([5, 7])
        ->and(array_column(contentQuery()->topPosts($period, TrafficDevice::Desktop, 10), 'postId'))->toBe([4]);
});

it('credits an account to the post its session opened first, across every visit of the session, and counts those whose session opened none', function () {
    // The feed, a post on it, then a game in the same session, the account credited to the game's visit.
    $feed = analyticsVisit('ann', ['arrived_at' => '2026-11-15 10:00:00']);
    $game = analyticsVisit('ann', ['arrived_at' => '2026-11-15 10:10:00', 'account_at' => '2026-11-15 10:20:00', 'signup_at' => '2026-11-15 10:19:00']);
    analyticsSession($feed, $game);
    analyticsVisitPost($feed, 11, ['first_at' => '2026-11-15 10:02:00']);
    analyticsVisitPost($game, 12, ['first_at' => '2026-11-15 10:12:00']);
    // Voted on from the feed before anything was opened: no post opened first.
    analyticsVisitPost($feed, 13, ['views' => 0, 'voted' => true, 'first_at' => '2026-11-15 10:01:00']);

    // Two posts first opened at the same moment: the lower id.
    $bob = analyticsVisit('bob', ['account_at' => '2026-11-15 10:20:00', 'device' => TrafficDevice::Desktop]);
    analyticsSession($bob);
    analyticsVisitPost($bob, 14, ['first_at' => '2026-11-15 10:05:00']);
    analyticsVisitPost($bob, 12, ['first_at' => '2026-11-15 10:05:00']);

    // An account whose session opened nothing, one whose session began before sessions were recorded, and one before the period.
    $cat = analyticsVisit('cat', ['account_at' => '2026-11-15 10:20:00']);
    analyticsSession($cat);
    analyticsVisit('dan', ['account_at' => '2026-11-15 10:20:00']);
    $old = analyticsVisit('eve', ['network_day' => '2026-10-01', 'arrived_at' => '2026-10-01 10:00:00', 'account_at' => '2026-10-01 10:20:00']);
    analyticsSession($old);
    analyticsVisitPost($old, 11, ['first_at' => '2026-10-01 10:02:00']);

    // Another browser's session never lends a post.
    $fay = analyticsVisit('fay');
    analyticsSession($fay);
    analyticsVisitPost($fay, 15);

    $period = analyticsFilter()->period;

    expect(contentQuery()->firstPostAccounts($period, null, [11, 12, 13, 14, 15]))->toBe([11 => 1, 12 => 1])
        ->and(contentQuery()->firstPostAccounts($period, TrafficDevice::Desktop, [11, 12]))->toBe([12 => 1])
        ->and(contentQuery()->firstPostAccounts($period, null, []))->toBe([])
        ->and(contentQuery()->unattributedAccounts($period, null))->toBe(2)
        ->and(contentQuery()->unattributedAccounts($period, TrafficDevice::Desktop))->toBe(0);
});

it('credits an account to the post its session opened first, though another was voted on before it was opened', function () {
    $visit = analyticsVisit('ann', ['arrived_at' => '2026-11-15 10:00:00', 'account_at' => '2026-11-15 10:20:00', 'signup_at' => '2026-11-15 10:19:00']);
    analyticsSession($visit);
    $posts = app(RecordVisitPostAction::class);

    $posts->actedOn($visit->id, 13, UserActivity::Voted, CarbonImmutable::parse('2026-11-15 10:01:00'));
    $posts->viewedOn($visit->id, 11, CarbonImmutable::parse('2026-11-15 10:02:00'));
    $posts->viewedOn($visit->id, 13, CarbonImmutable::parse('2026-11-15 10:03:00'));

    expect(contentQuery()->firstPostAccounts(analyticsFilter()->period, null, [11, 13]))->toBe([11 => 1]);
});

it('reads the posts given, a deleted one too', function () {
    $live = Post::factory()->published()->create(['title' => 'Glacier Blue Water']);
    $gone = Post::factory()->published()->create(['title' => 'Foggy Hilltop Town']);
    $gone->delete();

    $posts = contentQuery()->posts([$live->id, $gone->id, 999999]);

    expect($posts->keys()->sort()->values()->all())->toBe([$live->id, $gone->id])
        ->and($posts[$live->id]->title)->toBe('Glacier Blue Water')
        ->and($posts[$gone->id]->trashed())->toBeTrue()
        ->and(contentQuery()->posts([])->all())->toBe([]);
});

it('credits an account to the post its session opened first on a visit of another day, each browser once over the visits credited to it', function () {
    $account = fn (string $at): array => ['account_at' => $at, 'signup_at' => $at];

    // Credited on the 15th; the session goes on past midnight and opens a post on the 16th, after one only voted on.
    $ann = analyticsVisit('ann', ['network_day' => '2026-11-15', 'arrived_at' => '2026-11-15 23:50:00', ...$account('2026-11-15 23:55:00')]);
    $annLater = analyticsVisit('ann', ['network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 00:05:00']);
    analyticsSession($ann, $annLater);
    analyticsVisitPost($ann, 24, ['views' => 0, 'voted' => true, 'first_at' => '2026-11-15 23:52:00']);
    analyticsVisitPost($annLater, 21, ['first_at' => '2026-11-16 00:10:00']);
    // The same browser credited again in a session of its own that opened the same post first: one account of it.
    $annAgain = analyticsVisit('ann', ['network_day' => '2026-11-17', ...$account('2026-11-17 10:30:00')]);
    analyticsSession($annAgain);
    analyticsVisitPost($annAgain, 21, ['first_at' => '2026-11-17 10:05:00']);

    // Two posts opened at the same moment on two visits of the session: the lower id.
    $bob = analyticsVisit('bob', ['device' => TrafficDevice::Desktop, ...$account('2026-11-15 10:20:00')]);
    $bobLater = analyticsVisit('bob', ['device' => TrafficDevice::Desktop, 'arrived_at' => '2026-11-15 10:01:00']);
    analyticsSession($bob, $bobLater);
    analyticsVisitPost($bob, 23, ['first_at' => '2026-11-15 10:05:00']);
    analyticsVisitPost($bobLater, 22, ['first_at' => '2026-11-15 10:05:00']);

    // A browser credited twice: once in a session that opened nothing, once in one that opened a post.
    $cat = analyticsVisit('cat', ['network_day' => '2026-11-14', ...$account('2026-11-14 10:20:00')]);
    analyticsSession($cat);
    $catAgain = analyticsVisit('cat', ['network_day' => '2026-11-16', ...$account('2026-11-16 10:20:00')]);
    analyticsSession($catAgain);
    analyticsVisitPost($catAgain, 22);

    // A credited visit whose page never ran is no account of the figures, and one the day before the period none of them.
    $dan = analyticsVisit('dan', ['opened_at' => null, ...$account('2026-11-15 10:20:00')]);
    analyticsSession($dan);
    analyticsVisitPost($dan, 21);
    $old = analyticsVisit('eve', ['network_day' => '2026-11-11', 'arrived_at' => '2026-11-11 10:00:00', ...$account('2026-11-11 10:20:00')]);
    analyticsSession($old);
    analyticsVisitPost($old, 21, ['first_at' => '2026-11-11 10:02:00']);

    $period = analyticsFilter()->period;
    $accounts = contentQuery()->firstPostAccounts($period, null, [21, 22, 23, 24]);
    ksort($accounts);

    expect($accounts)->toBe([21 => 1, 22 => 2])
        ->and(contentQuery()->firstPostAccounts($period, TrafficDevice::Desktop, [21, 22, 23]))->toBe([22 => 1])
        ->and(contentQuery()->firstPostAccounts($period, TrafficDevice::Mobile, [23]))->toBe([])
        ->and(contentQuery()->unattributedAccounts($period, null))->toBe(1)
        ->and(contentQuery()->unattributedAccounts($period, TrafficDevice::Desktop))->toBe(0)
        // Only the days of the 16th on: ann's account is the 17th's, cat's the 16th's.
        ->and(contentQuery()->firstPostAccounts(analyticsFilter('custom', from: '2026-11-16', to: '2026-11-18')->period, null, [21, 22]))->toEqualCanonicalizing([21 => 1, 22 => 1])
        ->and(contentQuery()->unattributedAccounts(analyticsFilter('custom', from: '2026-11-16', to: '2026-11-18')->period, null))->toBe(0);
});
