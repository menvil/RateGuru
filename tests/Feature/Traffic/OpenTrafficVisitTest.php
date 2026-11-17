<?php

use App\Enums\GameStatus;
use App\Enums\TrafficChannel;
use App\Livewire\Games\PlayGame;
use App\Models\GameAttempt;
use App\Models\TrafficVisit;
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
