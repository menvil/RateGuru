<?php

use App\Actions\Traffic\MarkTrafficVisitAction;
use App\Actions\Traffic\RevalueOpenVisitsAction;
use App\Enums\GameStatus;
use App\Enums\TrafficChannel;
use App\Enums\TrafficEvent;
use App\Enums\TrafficKnownReason;
use App\Livewire\Games\PlayGame;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameResultProfile;
use App\Models\Post;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\User;
use App\Queries\Traffic\TrafficChanceSampleQuery;
use App\Services\Traffic\TrafficVisitWorth;
use App\Support\Traffic\Capture\ArrivalToken;
use App\Support\Traffic\Capture\TrafficArrival;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * The game page ran: its first call hands the arrival back. A paid click is
 * marked opened and given the game version; an unpaid arrival is recorded
 * only now — the same visit again within 30 minutes, none at all while a
 * paid click holds the browser — and a resumed attempt or a paused game opens
 * its visit too.
 *
 * Every visit belongs to the session it continues: a move inside the site or
 * a page opened with no referrer within 30 minutes of the browser's last
 * unpaid visit carries that session's first visit and where it came from;
 * anything from outside begins a session of its own, as a paid click always
 * does. Staff leave no unpaid visits, and a visit's details keep the page's
 * own path.
 *
 * While Record site visits is on, the feed, a post or a profile records a
 * visit too — one a session, worth nothing, outside the revaluation and the
 * chances — and a game reached from it continues that session.
 *
 * The campaign tags of the address a session entered by are kept on each of
 * its visits, and name its source with the referrer, its campaign and its
 * creative; an arrival tagged otherwise begins a session of its own, and a
 * network's unfilled placeholders are no tags.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-17 12:00:00');
    recordTraffic();
    $this->draft = publishableGameDraft();
    $this->version = liveGame($this->draft);
    allowGames();
    $this->game = $this->draft['game'];
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** The address of a game's shared result, as a share link gives it. */
function sharedResultOf(Game $game): string
{
    return route('games.result', ['slug' => $game->slug, 'version' => 1, 'profile' => GameResultProfile::query()->value('key')], false);
}

it('marks a paid click opened and gives it the version the visitor plays', function () {
    $visit = TrafficVisit::factory()->create(['visitor_hash' => browserHashOf(OPEN_TOKEN), 'game_id' => $this->game->id]);

    $page = arrivedAt($this->game->slug, ['visit' => $visit->id])->call('init');

    expect($page->get('visitId'))->toBe($visit->id)
        ->and($visit->fresh()->opened_at)->not->toBeNull()
        ->and($visit->fresh()->version_id)->toBe($this->version->id);

    $page->call('continue');
    expect(GameAttempt::query()->sole()->traffic_visit_id)->toBe($visit->id);
});

it('records an unpaid arrival only once the page runs, by the channel it came through', function () {
    $page = arrivedAt($this->game->slug, ['channel' => 'search', 'origin' => 'google', 'slug' => $this->game->slug]);

    expect(TrafficVisit::query()->count())->toBe(0);

    $page->call('init');
    $visit = TrafficVisit::query()->sole();

    expect($visit->channel)->toBe(TrafficChannel::Search)
        ->and($visit->origin)->toBe('google')
        ->and($visit->source_id)->toBeNull()
        ->and($visit->game_id)->toBe($this->game->id)
        ->and($visit->version_id)->toBe($this->version->id)
        ->and($visit->opened_at)->not->toBeNull()
        ->and($visit->known)->toBeFalse();
});

it('keeps one unpaid visit to a game within 30 minutes, and starts another after', function () {
    arrivedAt($this->game->slug, ['channel' => 'direct', 'slug' => $this->game->slug])->call('init');
    CarbonImmutable::setTestNow('2026-11-17 12:20:00');
    arrivedAt($this->game->slug, ['channel' => 'direct', 'slug' => $this->game->slug])->call('init');

    expect(TrafficVisit::query()->count())->toBe(1);

    CarbonImmutable::setTestNow('2026-11-17 13:00:00');
    arrivedAt($this->game->slug, ['channel' => 'direct', 'slug' => $this->game->slug])->call('init');

    expect(TrafficVisit::query()->count())->toBe(2);
});

it('credits an unpaid return to the paid click that still holds the browser', function () {
    $click = TrafficVisit::factory()->create(['visitor_hash' => browserHashOf(OPEN_TOKEN), 'holds_until' => now()->addDays(2)]);

    $page = arrivedAt($this->game->slug, ['channel' => 'direct', 'slug' => $this->game->slug])->call('init');

    expect(TrafficVisit::query()->count())->toBe(1)
        ->and($page->get('visitId'))->toBe($click->id)
        ->and($click->fresh()->game_id)->toBe($this->game->id);
});

it('records nothing for a crawler, for a token of another browser, or while recording is off', function () {
    $this->withCookie((string) config('games.visitor_cookie.name'), OPEN_TOKEN)->withHeaders(['User-Agent' => 'Googlebot/2.1'])
        ->post(route('traffic.signal'), ['signal' => 'opened', 'arrival' => ArrivalToken::make(['channel' => 'direct', 'slug' => $this->game->slug], browserHashOf(OPEN_TOKEN), now()->getTimestamp())])
        ->assertNoContent();

    app(TrafficArrival::class)->set(ArrivalToken::make(['channel' => 'direct', 'slug' => $this->game->slug], browserHashOf('someone-else-someone-else-someone-elsexx'), now()->getTimestamp()));
    Livewire::withCookie((string) config('games.visitor_cookie.name'), OPEN_TOKEN)->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT, 'Accept-Language' => 'en'])
        ->test(PlayGame::class, ['slug' => $this->game->slug])->call('init');

    recordTraffic(false);
    arrivedAt($this->game->slug, ['channel' => 'direct', 'slug' => $this->game->slug])->call('init');

    expect(TrafficVisit::query()->count())->toBe(0);
});

it('opens the visit of a paused game, and of a resumed attempt', function () {
    $visit = TrafficVisit::factory()->create(['visitor_hash' => browserHashOf(OPEN_TOKEN)]);
    $this->game->forceFill(['status' => GameStatus::Paused])->save();

    arrivedAt($this->game->slug, ['visit' => $visit->id])->call('init');

    expect($visit->fresh()->opened_at)->not->toBeNull();
});

it('takes the arrival token from the page, so the layout prints none', function () {
    $page = $this->withCookie((string) config('games.visitor_cookie.name'), OPEN_TOKEN)->get('/games/'.$this->game->slug)->assertOk();

    expect($page->getContent())->not->toContain('rg-arrival');
});

it('opens the paid click holding the browser on the first page that runs, when its own landing sent the visitor to sign in', function () {
    $held = TrafficVisit::factory()->create(['visitor_hash' => browserHashOf(OPEN_TOKEN), 'arrived_at' => now()->subMinutes(5), 'holds_until' => now()->addDays(7), 'opened_at' => null]);

    arrivedAt($this->game->slug, ['channel' => 'direct', 'slug' => $this->game->slug])->call('init');

    expect($held->fresh()->opened_at)->not->toBeNull()
        ->and($held->fresh()->game_id)->toBe($this->game->id)
        ->and(TrafficVisit::query()->count())->toBe(1);
});

it('begins a session with the visit itself, keyed by where it came from and the page it landed on', function () {
    $visitId = pageRuns('/games/'.$this->game->slug, ['Referer' => 'https://www.google.co.uk/search?q=taste']);
    $visit = TrafficVisit::query()->sole();

    expect($visitId)->toBe($visit->id)
        ->and($visit->entry_visit_id)->toBe($visit->id)
        ->and($visit->channel)->toBe(TrafficChannel::Search)
        ->and($visit->source_key)->toBe('google:organic')
        ->and($visit->landing_key)->toBe('games.play:'.$this->game->id.':'.$this->version->id)
        ->and([$visit->campaign_key, $visit->creative_key])->toBe([null, null]);
});

it('keeps a game, its result and a game reached from that result in one session', function () {
    $other = publishableGameDraft();
    liveGame($other);

    pageRuns('/games/'.$this->game->slug, ['Referer' => 'https://www.google.com/']);
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    pageRuns(sharedResultOf($this->game), ['Referer' => url('/games/'.$this->game->slug)]);

    // The result of the game played is that game's visit still.
    expect(TrafficVisit::query()->count())->toBe(1);

    CarbonImmutable::setTestNow('2026-11-17 12:10:00');
    pageRuns('/games/'.$other['game']->slug, ['Referer' => url(sharedResultOf($this->game))]);

    [$entry, $next] = TrafficVisit::query()->orderBy('id')->get()->all();

    expect($entry->entry_visit_id)->toBe($entry->id)
        ->and($next->entry_visit_id)->toBe($entry->id)
        ->and($next->game_id)->toBe($other['game']->id)
        // The channel says how this page was reached; the keys, how the session was.
        ->and($next->channel)->toBe(TrafficChannel::Shared)
        ->and($next->origin)->toBe('result page')
        ->and([$next->source_key, $next->landing_key])->toBe([$entry->source_key, $entry->landing_key])
        ->and($next->source_key)->toBe('google:organic')
        ->and($entry->last_seen_at->toDateTimeString())->toBe('2026-11-17 12:05:00');
});

it('carries the session’s first visit and its keys to a page opened with no referrer, until 30 minutes have passed', function () {
    $other = publishableGameDraft();
    $otherVersion = liveGame($other);

    pageRuns('/games/'.$this->game->slug, ['Referer' => 'https://old.reddit.com/r/games/']);
    CarbonImmutable::setTestNow('2026-11-17 12:20:00');
    pageRuns('/games/'.$other['game']->slug);
    CarbonImmutable::setTestNow('2026-11-17 12:50:00');
    pageRuns('/games/'.$this->game->slug);

    [$entry, $continued, $later] = TrafficVisit::query()->orderBy('id')->get()->all();

    expect($continued->channel)->toBe(TrafficChannel::Direct)
        ->and($continued->entry_visit_id)->toBe($entry->id)
        ->and($continued->source_key)->toBe('reddit:organic')
        ->and($continued->landing_key)->toBe('games.play:'.$this->game->id.':'.$this->version->id)
        ->and($continued->version_id)->toBe($otherVersion->id)
        // Seen last at 12:20: by 12:50 that session is over.
        ->and($later->entry_visit_id)->toBe($later->id)
        ->and($later->source_key)->toBe('direct');
});

it('begins a session of its own from another site, a search or a result page reached from outside', function () {
    $other = publishableGameDraft();
    liveGame($other);

    pageRuns('/games/'.$this->game->slug, ['Referer' => 'https://www.google.com/']);
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    pageRuns('/games/'.$other['game']->slug, ['Referer' => 'https://l.facebook.com/l.php']);
    CarbonImmutable::setTestNow('2026-11-17 12:10:00');
    pageRuns(sharedResultOf($this->game));

    $visits = TrafficVisit::query()->orderBy('id')->get();

    expect($visits)->toHaveCount(3)
        ->and($visits->every(fn (TrafficVisit $visit): bool => $visit->entry_visit_id === $visit->id))->toBeTrue()
        ->and($visits->pluck('source_key')->all())->toBe(['google:organic', 'facebook:organic', 'shared'])
        ->and($visits[2]->landing_key)->toBe('games.result:'.$this->game->id)
        ->and($visits[2]->game_id)->toBe($this->game->id);
});

it('continues the session of the visit seen last, after a reload made an earlier one the latest', function () {
    $second = publishableGameDraft();
    liveGame($second);
    $third = publishableGameDraft();
    liveGame($third);

    pageRuns('/games/'.$this->game->slug, ['Referer' => 'https://www.google.com/']);
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    pageRuns('/games/'.$second['game']->slug, ['Referer' => 'https://www.reddit.com/']);
    // Back to the search results, and the first game again: a reload of the first visit.
    CarbonImmutable::setTestNow('2026-11-17 12:10:00');
    pageRuns('/games/'.$this->game->slug, ['Referer' => 'https://www.google.com/']);
    CarbonImmutable::setTestNow('2026-11-17 12:12:00');
    pageRuns('/games/'.$third['game']->slug, ['Referer' => url('/games/'.$this->game->slug)]);

    [$google, $reddit, $next] = TrafficVisit::query()->orderBy('id')->get()->all();

    expect($reddit->source_key)->toBe('reddit:organic')
        ->and($next->game_id)->toBe($third['game']->id)
        ->and($next->entry_visit_id)->toBe($google->id)
        ->and($next->source_key)->toBe('google:organic');
});

it('begins a session from a search on a page first reached inside the site, never taking it for a reload', function () {
    $other = publishableGameDraft();
    $otherVersion = liveGame($other);

    pageRuns('/games/'.$this->game->slug, ['Referer' => 'https://www.google.com/']);
    CarbonImmutable::setTestNow('2026-11-17 12:02:00');
    pageRuns('/games/'.$other['game']->slug, ['Referer' => url('/games/'.$this->game->slug)]);
    CarbonImmutable::setTestNow('2026-11-17 12:20:00');
    pageRuns('/games/'.$other['game']->slug, ['Referer' => 'https://www.google.com/']);

    $visits = TrafficVisit::query()->orderBy('id')->get();

    expect($visits)->toHaveCount(3)
        ->and($visits[1]->entry_visit_id)->toBe($visits[0]->id)
        ->and($visits[2]->entry_visit_id)->toBe($visits[2]->id)
        ->and($visits[2]->source_key)->toBe('google:organic')
        ->and($visits[2]->landing_key)->toBe('games.play:'.$other['game']->id.':'.$otherVersion->id);
});

it('takes the same page from the same place again — a reload — as the same visit', function () {
    pageRuns('/games/'.$this->game->slug, ['Referer' => 'https://www.google.com/']);
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    pageRuns('/games/'.$this->game->slug, ['Referer' => 'https://www.google.com/']);

    expect(TrafficVisit::query()->sole()->last_seen_at->toDateTimeString())->toBe('2026-11-17 12:05:00');
});

it('keeps the page’s own path and referrer in a visit’s details, never the address its script called', function () {
    pageRuns('/games/'.$this->game->slug, ['Referer' => 'https://www.google.com/']);
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    pageRuns(sharedResultOf($this->game), ['Referer' => 'https://news.ycombinator.com/item?id=1']);

    expect(TrafficVisitDetail::query()->orderBy('visit_id')->get(['landing_path', 'referrer_host'])->toArray())->toBe([
        ['landing_path' => '/games/'.$this->game->slug, 'referrer_host' => 'google.com'],
        ['landing_path' => sharedResultOf($this->game), 'referrer_host' => 'news.ycombinator.com'],
    ]);
});

it('records no unpaid visit for staff, and their paid clicks as anyone’s', function (string $role) {
    $staff = User::factory()->{$role}()->create();
    TrafficSource::factory()->create(['link_key' => 'exo']);

    app(TrafficArrival::class)->set(ArrivalToken::make(['channel' => 'direct', 'slug' => $this->game->slug, 'route' => 'games.play', 'path' => '/games/'.$this->game->slug], browserHashOf(OPEN_TOKEN), now()->getTimestamp()));
    $page = Livewire::actingAs($staff)->withCookie((string) config('games.visitor_cookie.name'), OPEN_TOKEN)
        ->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])
        ->test(PlayGame::class, ['slug' => $this->game->slug])->call('init');

    expect($page->get('visitId'))->toBeNull()
        ->and(TrafficVisit::query()->count())->toBe(0);

    $this->actingAs($staff);
    clickOn('/games/'.$this->game->slug.'?rg_src=exo&cid=staff-check', OPEN_TOKEN)->assertOk();
    $click = TrafficVisit::query()->sole();
    $opened = Livewire::actingAs($staff)->withCookie((string) config('games.visitor_cookie.name'), OPEN_TOKEN)
        ->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])
        ->test(PlayGame::class, ['slug' => $this->game->slug])->call('init');

    expect($opened->get('visitId'))->toBe($click->id)
        ->and($click->fresh()->opened_at)->not->toBeNull()
        ->and($click->known_reason)->toBe(TrafficKnownReason::SignedIn);
})->with(['admin', 'moderator']);

it('records a signed-in member’s unpaid visit as a known one', function () {
    app(TrafficArrival::class)->set(ArrivalToken::make(['channel' => 'direct', 'slug' => $this->game->slug], browserHashOf(OPEN_TOKEN), now()->getTimestamp()));
    Livewire::actingAs(User::factory()->create())->withCookie((string) config('games.visitor_cookie.name'), OPEN_TOKEN)
        ->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])
        ->test(PlayGame::class, ['slug' => $this->game->slug])->call('init');

    expect(TrafficVisit::query()->sole()->known_reason)->toBe(TrafficKnownReason::SignedIn);
});

it('gives a paid click’s landing the version its page shows once it runs, and adds no unpaid visit while the click holds the browser', function () {
    $other = publishableGameDraft();
    liveGame($other);
    $source = TrafficSource::factory()->create(['link_key' => 'exo']);

    clickOn('/games/'.$this->game->slug.'?rg_src=exo&cid=landing-1', OPEN_TOKEN)->assertOk();
    $visitId = Livewire::withCookie((string) config('games.visitor_cookie.name'), OPEN_TOKEN)
        ->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])
        ->test(PlayGame::class, ['slug' => $this->game->slug])->call('init')->get('visitId');
    $click = TrafficVisit::query()->sole();

    expect($visitId)->toBe($click->id)
        ->and($click->entry_visit_id)->toBe($click->id)
        ->and($click->source_key)->toBe('paid:'.$source->id)
        ->and($click->landing_key)->toBe('games.play:'.$this->game->id.':'.$this->version->id);

    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    pageRuns('/games/'.$other['game']->slug, ['Referer' => url('/games/'.$this->game->slug)]);

    expect(TrafficVisit::query()->count())->toBe(1)
        ->and($click->fresh()->landing_key)->toBe('games.play:'.$this->game->id.':'.$this->version->id);
});

it('records a visit to the site’s own pages only while Record site visits is on, one a session, from where the session came', function () {
    $post = Post::factory()->published()->create();

    expect(clickOn('/', OPEN_TOKEN)->assertOk()->getContent())->not->toContain('rg-arrival');

    recordTraffic(site: true);
    pageRuns('/', ['Referer' => 'https://old.reddit.com/r/pics/']);
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    pageRuns('/posts/'.$post->id, ['Referer' => url('/')]);
    $visit = TrafficVisit::query()->sole();

    expect($visit->channel)->toBe(TrafficChannel::Referral)
        ->and([$visit->game_id, $visit->source_id])->toBe([null, null])
        ->and($visit->entry_visit_id)->toBe($visit->id)
        ->and([$visit->source_key, $visit->landing_key])->toBe(['reddit:organic', 'feed'])
        // The post read in the same session is the same visit, seen again.
        ->and($visit->last_seen_at->toDateTimeString())->toBe('2026-11-17 12:05:00')
        ->and(TrafficVisitDetail::query()->sole()->landing_path)->toBe('/');

    // A token the feed was given before the switch went off records nothing once it is.
    $page = clickOn('/', 'laterBrowserlaterBrowserlaterBrowserlate');
    recordTraffic();
    preg_match('/<meta name="rg-arrival" content="([^"]+)">/', (string) $page->getContent(), $meta);
    $this->withCookie((string) config('games.visitor_cookie.name'), 'laterBrowserlaterBrowserlaterBrowserlate')
        ->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])
        ->post(route('traffic.signal'), ['signal' => 'opened', 'arrival' => html_entity_decode($meta[1])])
        ->assertNoContent();

    expect(TrafficVisit::query()->count())->toBe(1);
});

it('keeps the feed and a game reached from it in one session, the game’s visit inside the site and entered by the feed', function () {
    recordTraffic(site: true);
    pageRuns('/', ['Referer' => 'https://old.reddit.com/r/pics/']);
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    $visitId = pageRuns('/games/'.$this->game->slug, ['Referer' => url('/')]);

    [$feed, $game] = TrafficVisit::query()->orderBy('id')->get()->all();

    expect($visitId)->toBe($game->id)
        ->and($game->game_id)->toBe($this->game->id)
        ->and($game->channel)->toBe(TrafficChannel::Internal)
        ->and($game->origin)->toBe('feed')
        ->and($game->entry_visit_id)->toBe($feed->id)
        ->and([$game->source_key, $game->landing_key])->toBe(['reddit:organic', 'feed']);
});

it('keeps a game Traffic sources counted once as one visit when the feed came from elsewhere in between', function (bool $site, string $feed, array $headers) {
    recordTraffic(site: $site);
    pageRuns('/games/'.$this->game->slug, ['Referer' => 'https://old.reddit.com/r/games/']);
    CarbonImmutable::setTestNow('2026-11-17 12:10:00');
    // Without site visits the feed carries no token, so nothing of it runs.
    $site ? pageRuns($feed, $headers) : clickOn($feed, OPEN_TOKEN, $headers)->assertOk();
    CarbonImmutable::setTestNow('2026-11-17 12:12:00');
    pageRuns('/games/'.$this->game->slug, ['Referer' => url('/')]);

    $tracked = TrafficVisit::query()->tracked()->sole();

    expect($tracked->channel)->toBe(TrafficChannel::Referral)
        ->and($tracked->last_seen_at->toDateTimeString())->toBe('2026-11-17 12:12:00')
        ->and(TrafficVisit::query()->count())->toBe($site ? 2 : 1);
})->with([
    'feed from a search, site visits off' => [false, '/', ['Referer' => 'https://www.google.com/']],
    'feed from a search, site visits on' => [true, '/', ['Referer' => 'https://www.google.com/']],
    'tagged feed, site visits on' => [true, '/?utm_source=news&utm_medium=email&utm_campaign=w1', []],
]);

it('values a visit to the rest of the site at nothing, whatever it reaches, and leaves it out of the revaluation and the chances', function () {
    recordTraffic(site: true);
    // Chances every slice can lean on: as a visit the tracker counted, the feed's steps would be worth something.
    finishedChanceRun(['*' => ['opened' => ['chance' => 0.2], 'signup' => ['chance' => 0.5]]]);
    pageRuns('/', ['Referer' => 'https://www.google.com/']);
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    pageRuns('/games/'.$this->game->slug, ['Referer' => url('/')]);
    [$feed, $game] = TrafficVisit::query()->orderBy('id')->get()->all();
    app(MarkTrafficVisitAction::class)->handle($feed->id, TrafficEvent::Signup, CarbonImmutable::now(), ['signup_at' => CarbonImmutable::now()]);

    expect($feed->fresh())->value_real->toBe('0.00000000')->value_expected->toBe('0.00000000')
        ->and(app(TrafficVisitWorth::class)->values($feed->fresh(), CarbonImmutable::now())['expected'])->not->toBe('0.00000000')
        ->and($game->value_expected)->not->toBe('0.00000000');

    // Whatever it holds, the revaluation never works it out again.
    TrafficVisit::query()->whereKey($feed->id)->update(['value_expected' => '0.3']);
    app(RevalueOpenVisitsAction::class)->handle(CarbonImmutable::now());

    expect($feed->fresh()->value_expected)->toBe('0.30000000');

    // Once both have matured, the chances are worked out from the game's visit alone.
    CarbonImmutable::setTestNow('2026-12-02 12:00:00');

    expect(collect(app(TrafficChanceSampleQuery::class)->rows(CarbonImmutable::now()))->sum('visits'))->toBe(1);
});

it('leaves the paid click holding the browser as it was when a page without a game runs, and opens it on the next game', function () {
    recordTraffic(site: true);
    $held = TrafficVisit::factory()->create(['visitor_hash' => browserHashOf(OPEN_TOKEN), 'game_id' => $this->game->id, 'arrived_at' => now()->subMinutes(5), 'last_seen_at' => now()->subMinutes(5), 'holds_until' => now()->addDays(7), 'opened_at' => null]);

    // Its landing sent the visitor to sign in: the sign-in page and the feed run, neither a step of the click's.
    pageRuns('/login');
    pageRuns('/');

    expect(TrafficVisit::query()->count())->toBe(1)
        ->and($held->fresh())->opened_at->toBeNull()->last_seen_at->toDateTimeString()->toBe('2026-11-17 11:55:00')
        ->and($held->conversions()->count())->toBe(0);

    pageRuns('/games/'.$this->game->slug);

    expect($held->fresh()->opened_at)->not->toBeNull()
        ->and(TrafficVisit::query()->count())->toBe(1);
});

it('keeps the tags a session came by on its first visit, naming its source, campaign and creative by them', function () {
    pageRuns('/games/'.$this->game->slug.'?utm_source=reddit&utm_medium=social&utm_campaign=Launch%20week&utm_term=taste+quiz', ['Referer' => 'https://old.reddit.com/r/games/']);
    $visit = TrafficVisit::query()->sole();

    expect($visit->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term']))->toBe([
        'utm_source' => 'reddit',
        'utm_medium' => 'social',
        'utm_campaign' => 'Launch week',
        'utm_content' => null,
        'utm_term' => 'taste quiz',
    ])
        // The channel stays how the page was reached; the keys say how the session was.
        ->and($visit->channel)->toBe(TrafficChannel::Referral)
        ->and($visit->entry_visit_id)->toBe($visit->id)
        ->and($visit->source_key)->toBe('reddit:organic')
        ->and($visit->campaign_key)->toBe('tag:Launch week')
        ->and($visit->creative_key)->toBe('taste quiz')
        ->and($visit->landing_key)->toBe('games.play:'.$this->game->id.':'.$this->version->id)
        ->and(TrafficVisitDetail::query()->sole()->landing_path)->toBe('/games/'.$this->game->slug);
});

it('copies the session’s tags onto the visits that continue it, and takes its own link followed again for the same session', function () {
    recordTraffic(site: true);
    $newsletter = '/?utm_source=newsletter&utm_campaign=weekly-12&utm_content=hero';

    pageRuns($newsletter);
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    pageRuns('/games/'.$this->game->slug, ['Referer' => url('/')]);
    CarbonImmutable::setTestNow('2026-11-17 12:10:00');
    pageRuns($newsletter);

    [$feed, $game] = TrafficVisit::query()->orderBy('id')->get()->all();
    $tags = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    expect(TrafficVisit::query()->count())->toBe(2)
        ->and($feed->channel)->toBe(TrafficChannel::Direct)
        ->and([$feed->source_key, $feed->campaign_key, $feed->creative_key])->toBe(['email:email', 'tag:weekly-12', 'hero'])
        ->and($game->channel)->toBe(TrafficChannel::Internal)
        ->and($game->entry_visit_id)->toBe($feed->id)
        ->and($game->only($tags))->toBe($feed->only($tags))
        ->and($game->utm_campaign)->toBe('weekly-12')
        ->and([$game->source_key, $game->campaign_key, $game->creative_key, $game->landing_key])->toBe([$feed->source_key, $feed->campaign_key, $feed->creative_key, 'feed'])
        // The newsletter's link opened again is the feed's visit of the same session, seen again.
        ->and($feed->last_seen_at->toDateTimeString())->toBe('2026-11-17 12:10:00');
});

it('begins a session of its own on tags other than the session’s, whether with no referrer, inside the site or from the same network', function () {
    recordTraffic(site: true);
    $post = Post::factory()->published()->create();

    pageRuns('/?utm_source=newsletter&utm_campaign=weekly-12');
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    pageRuns('/?utm_source=newsletter&utm_campaign=weekly-13');
    CarbonImmutable::setTestNow('2026-11-17 12:10:00');
    pageRuns('/games/'.$this->game->slug.'?utm_campaign=house-promo', ['Referer' => url('/')]);
    CarbonImmutable::setTestNow('2026-11-17 12:15:00');
    pageRuns('/posts/'.$post->id.'?utm_source=reddit&utm_campaign=a', ['Referer' => 'https://www.reddit.com/r/pics/']);
    CarbonImmutable::setTestNow('2026-11-17 12:20:00');
    pageRuns('/posts/'.$post->id.'?utm_source=reddit&utm_campaign=b', ['Referer' => 'https://www.reddit.com/r/pics/']);
    // The second reddit link followed again: a reload of its own visit.
    CarbonImmutable::setTestNow('2026-11-17 12:25:00');
    pageRuns('/posts/'.$post->id.'?utm_source=reddit&utm_campaign=b', ['Referer' => 'https://www.reddit.com/r/pics/']);

    $visits = TrafficVisit::query()->orderBy('id')->get();

    expect($visits)->toHaveCount(5)
        ->and($visits->every(fn (TrafficVisit $visit): bool => $visit->entry_visit_id === $visit->id))->toBeTrue()
        ->and($visits->pluck('campaign_key')->all())->toBe(['tag:weekly-12', 'tag:weekly-13', 'tag:house-promo', 'tag:a', 'tag:b'])
        ->and($visits->pluck('source_key')->all())->toBe(['email:email', 'email:email', 'internal', 'reddit:organic', 'reddit:organic'])
        ->and($visits[2]->channel)->toBe(TrafficChannel::Internal)
        ->and($visits[4]->last_seen_at->toDateTimeString())->toBe('2026-11-17 12:25:00');
});

it('counts utm_source=reddit without a medium and a reddit referrer under one key, a person once however many of its hosts they came through', function () {
    recordTraffic(site: true);
    $other = 'otherBrowserotherBrowserotherBrowserothe';

    // Reddit's app opens the link with no referrer; its tag says where it came from.
    pageRuns('/?utm_source=reddit');
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    pageRuns('/games/'.$this->game->slug, ['Referer' => 'https://out.reddit.com/t3_1abc']);
    CarbonImmutable::setTestNow('2026-11-17 12:40:00');
    pageRuns('/', ['Referer' => 'https://old.reddit.com/r/pics/']);
    pageRuns('/', ['Referer' => 'https://www.reddit.com/r/pics/'], $other);

    $visits = TrafficVisit::query()->orderBy('id')->get();

    expect($visits)->toHaveCount(4)
        ->and($visits->pluck('utm_source')->all())->toBe(['reddit', null, null, null])
        ->and($visits->every(fn (TrafficVisit $visit): bool => $visit->entry_visit_id === $visit->id))->toBeTrue()
        ->and(TrafficVisit::query()->distinct()->pluck('source_key')->all())->toBe(['reddit:organic'])
        ->and(TrafficVisit::query()->where('source_key', 'reddit:organic')->distinct()->count('visitor_hash'))->toBe(2)
        ->and(TrafficVisit::query()->where('source_key', 'reddit:organic')->where('visitor_hash', browserHashOf(OPEN_TOKEN))->distinct()->count('visitor_hash'))->toBe(1);
});

it('gives search tagged as paid a key of its own beside the search engine’s own visitors', function () {
    pageRuns('/games/'.$this->game->slug.'?utm_source=google&utm_medium=cpc&utm_campaign=brand', ['Referer' => 'https://www.google.com/']);
    pageRuns('/games/'.$this->game->slug, ['Referer' => 'https://www.google.com/'], 'otherBrowserotherBrowserotherBrowserothe');

    [$tagged, $organic] = TrafficVisit::query()->orderBy('id')->get()->all();

    expect([$tagged->channel, $organic->channel])->each->toBe(TrafficChannel::Search)
        ->and([$tagged->source_id, $tagged->holds_until])->toBe([null, null])
        ->and([$tagged->source_key, $tagged->campaign_key])->toBe(['google:paid', 'tag:brand'])
        ->and([$organic->source_key, $organic->campaign_key])->toBe(['google:organic', null]);
});

it('takes a network’s unfilled placeholders for no tags at all', function () {
    pageRuns('/games/'.$this->game->slug.'?utm_source={source}&utm_medium=[MEDIUM]&utm_campaign=%23%23CAMPAIGN%23%23&utm_content=${CREATIVE}&utm_term=%257Bkeyword%257D', ['Referer' => 'https://www.google.com/']);
    $visit = TrafficVisit::query()->sole();

    expect($visit->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term']))->each->toBeNull()
        ->and([$visit->source_key, $visit->campaign_key, $visit->creative_key])->toBe(['google:organic', null, null]);

    // Nor do they take the browser out of its session.
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    pageRuns('/games/'.$this->game->slug.'?utm_campaign={campaign}', ['Referer' => 'https://www.google.com/']);

    expect(TrafficVisit::query()->sole()->last_seen_at->toDateTimeString())->toBe('2026-11-17 12:05:00');
});
