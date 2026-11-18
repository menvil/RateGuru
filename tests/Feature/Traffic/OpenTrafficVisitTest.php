<?php

use App\Enums\GameStatus;
use App\Enums\TrafficChannel;
use App\Enums\TrafficKnownReason;
use App\Livewire\Games\PlayGame;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameResultProfile;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\User;
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
 */
const OPEN_TOKEN = 'openTokenopenTokenopenTokenopenTokenopen';

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

/** The game page as drawn with the given arrival, played by the browser holding OPEN_TOKEN. */
function arrivedAt(string $slug, array $arrival, string $token = OPEN_TOKEN)
{
    app(TrafficArrival::class)->set(ArrivalToken::make($arrival, browserHashOf($token), now()->getTimestamp()));

    return Livewire::withCookie((string) config('games.visitor_cookie.name'), $token)
        ->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT, 'Accept-Language' => 'en'])
        ->test(PlayGame::class, ['slug' => $slug]);
}

/**
 * The browser holding OPEN_TOKEN asks for a page with only the given headers,
 * and the page runs: a game's page makes its first call with the token it was
 * drawn with, any other page sends its script's signal. Returns the visit a
 * game's steps go to.
 */
function pageRuns(string $path, array $headers = []): ?int
{
    test()->flushHeaders();
    $page = clickOn($path, OPEN_TOKEN, $headers)->assertOk();
    test()->flushHeaders();

    if (preg_match('/<meta name="rg-arrival" content="([^"]+)">/', (string) $page->getContent(), $meta) === 1) {
        test()->withCookie((string) config('games.visitor_cookie.name'), OPEN_TOKEN)
            ->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT, 'Accept-Language' => 'en'])
            ->post(route('traffic.signal'), ['signal' => 'opened', 'arrival' => html_entity_decode($meta[1])])
            ->assertNoContent();

        return null;
    }

    return Livewire::withCookie((string) config('games.visitor_cookie.name'), OPEN_TOKEN)
        ->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT, 'Accept-Language' => 'en'])
        ->test(PlayGame::class, ['slug' => explode('/', trim($path, '/'))[1]])
        ->call('init')
        ->get('visitId');
}

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
