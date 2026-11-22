<?php

use App\Actions\Traffic\CopyCreditedKeysAction;
use App\Actions\Traffic\RecordPaidClickAction;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficChannel;
use App\Enums\TrafficCostUnit;
use App\Enums\TrafficDevice;
use App\Enums\TrafficSourceStatus;
use App\Enums\TrafficSpendBasis;
use App\Enums\TrafficSpendMode;
use App\Http\Middleware\CaptureTrafficArrival;
use App\Models\AccountAttribution;
use App\Models\GameResultProfile;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Traffic\Capture\ArrivalToken;
use App\Support\Traffic\Capture\TrafficArrival;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

/**
 * A paid click is recorded when its page is asked for, before anything is
 * drawn: on any page of the site, before the guest-viewing check sends the
 * visitor to sign in, once per network click id; and a page without rg_src
 * that is no game costs nothing. While Record site visits is on too, the
 * site's own pages carry their unpaid arrival as a game page does, by the
 * channel they were reached through; the administration, a game's preview,
 * share redirects and the health check never do. An unpaid arrival's token
 * carries the campaign tags of the page's address, never past the length
 * that is read back; a paid click keeps its tags beside its own keys.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-17 12:00:00');
    recordTraffic();
    $this->source = TrafficSource::factory()->create(['link_key' => 'exo', 'reporting_timezone' => 'America/New_York']);
    $this->draft = publishableGameDraft();
    liveGame($this->draft);
    allowGames();
    $this->slug = $this->draft['game']->slug;
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** The arrival a page printed for its script, as the tracker reads it back; null when it printed none. */
function printedArrival(TestResponse $page, string $token = 'browserAbrowserAbrowserAbrowserAbrowserA'): ?array
{
    preg_match('/<meta name="rg-arrival" content="([^"]+)">/', (string) $page->getContent(), $meta);

    return isset($meta[1]) ? ArrivalToken::read(html_entity_decode($meta[1]), browserHashOf($token), now()->getTimestamp()) : null;
}

/**
 * What the capture middleware itself costs a request for the page: the reads
 * of the cache and the queries it makes, the page drawn being left out.
 *
 * @return array{reads: int, queries: int}
 */
function captureCostOf(string $path): array
{
    $request = Request::create($path, 'GET', server: ['HTTP_USER_AGENT' => DESKTOP_CHROME_USER_AGENT]);
    $route = app('router')->getRoutes()->match($request);
    $request->setRouteResolver(fn () => $route);
    $cost = ['reads' => 0, 'queries' => 0];
    $counting = true;
    Event::listen(RetrievingKey::class, function () use (&$cost, &$counting) {
        $cost['reads'] += $counting ? 1 : 0;
    });
    DB::listen(function () use (&$cost, &$counting) {
        $cost['queries'] += $counting ? 1 : 0;
    });

    app(CaptureTrafficArrival::class)->handle($request, fn () => new Response('<html></html>', 200, ['Content-Type' => 'text/html']));
    $counting = false;

    return $cost;
}

it('records a paid click on a game page before the page is drawn, with its campaign, site, zone and price', function () {
    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-1&campaign=5521904&site=40123&zone=44718&cost=0.00045&utm_term=pop')->assertOk();

    $visit = TrafficVisit::query()->sole();
    $detail = TrafficVisitDetail::query()->sole();
    $campaign = TrafficCampaign::query()->sole();

    expect($visit->channel)->toBe(TrafficChannel::Network)
        ->and($visit->source_id)->toBe($this->source->id)
        ->and($visit->campaign_id)->toBe($campaign->id)
        ->and([$visit->site_ref, $visit->zone_ref, $visit->cost])->toBe(['40123', '44718', '0.00045000'])
        ->and($visit->click_hash)->toBe(hash('sha256', 'tag-1'))
        ->and($visit->visitor_hash)->toBe(browserHashOf('browserAbrowserAbrowserAbrowserAbrowserA'))
        ->and($visit->game_id)->toBe($this->draft['game']->id)
        ->and($visit->device)->toBe(TrafficDevice::Desktop)
        ->and($visit->network_day)->toBe('2026-11-17')
        ->and($visit->holds_until->toIso8601String())->toBe('2026-11-24T12:00:00+00:00')
        ->and($visit->opened_at)->toBeNull()
        ->and($detail->click_id)->toBe('tag-1')
        ->and($detail->extra_params)->toBe(['utm_term' => 'pop'])
        ->and($detail->landing_path)->toBe('/games/'.$this->slug)
        ->and($campaign->external_id)->toBe('5521904')
        ->and($campaign->status)->toBe(TrafficCampaignStatus::New)
        ->and($campaign->events()->sole()->summary)->toBe('First click with campaign ID 5521904');
});

it('hands the page a token of the click, which the layout prints on any page but a game, with the post a post’s page shows', function () {
    $post = Post::factory()->published()->create();

    $page = clickOn('/posts/'.$post->id.'?rg_src=exo&cid=tag-2')->assertOk();
    preg_match('/<meta name="rg-arrival" content="([^"]+)">/', $page->getContent(), $meta);

    expect(ArrivalToken::read($meta[1] ?? null, browserHashOf('browserAbrowserAbrowserAbrowserAbrowserA'), now()->getTimestamp()))->toBe(['visit' => TrafficVisit::query()->sole()->id, 'post' => $post->id]);

    $page = clickOn('/about?rg_src=exo&cid=tag-2', 'browserBbrowserBbrowserBbrowserBbrowserB')->assertOk();
    preg_match('/<meta name="rg-arrival" content="([^"]+)">/', $page->getContent(), $meta);

    expect(ArrivalToken::read($meta[1] ?? null, browserHashOf('browserBbrowserBbrowserBbrowserBbrowserB'), now()->getTimestamp()))->toBe(['visit' => TrafficVisit::query()->latest('id')->value('id')]);
});

it('gives a browser arriving through a paid link its cookie', function () {
    $this->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])->get('/?rg_src=exo&cid=tag-3')->assertOk()->assertCookie((string) config('games.visitor_cookie.name'));

    expect(TrafficVisit::query()->count())->toBe(1);
});

it('records the click before the guest-viewing check sends the visitor to sign in', function () {
    $settings = ProjectSettings::query()->find(1) ?? ProjectSettings::factory()->create();
    $settings->forceFill(['feature_flags' => [...($settings->feature_flags ?? []), 'allow_guest_viewing' => false]])->save();
    app(ProjectSettingsManager::class)->flush();

    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-4')->assertRedirect(route('login'));

    expect(TrafficVisit::query()->count())->toBe(1);
});

it('counts one network click once, however often its page is asked for', function () {
    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-5');
    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-5');

    expect(TrafficVisit::query()->count())->toBe(1);
});

it('passes a click no browser has run yet to the browser that asks for it, and keeps an opened one with its own', function () {
    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-6', 'prefetchprefetchprefetchprefetchprefetch');
    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-6', 'personApersonApersonApersonApersonAperso');

    $visit = TrafficVisit::query()->sole();
    expect($visit->visitor_hash)->toBe(browserHashOf('personApersonApersonApersonApersonAperso'));

    $visit->forceFill(['opened_at' => now()])->save();
    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-6', 'thirdBthirdBthirdBthirdBthirdBthirdBthir');

    expect(TrafficVisit::query()->sole()->visitor_hash)->toBe(browserHashOf('personApersonApersonApersonApersonAperso'));
});

it('prices a click passed to another browser for the device that took it, and leaves a spend taken from a day’s figures as it is', function (?string $allocated, string $spend, TrafficSpendBasis $basis) {
    $this->source->forceFill(['spend_mode' => TrafficSpendMode::Rate, 'rate_desktop' => '0.002', 'rate_mobile' => '0.005', 'rate_unit' => TrafficCostUnit::Click])->save();
    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-x', 'prefetchprefetchprefetchprefetchprefetch');

    expect(TrafficVisit::query()->sole())->device->toBe(TrafficDevice::Desktop)->spend->toBe('0.00200000');

    if ($allocated !== null) {
        TrafficVisit::query()->update(['spend' => $allocated, 'spend_basis' => TrafficSpendBasis::Api->value, 'spend_estimated' => false]);
    }

    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-x', 'personApersonApersonApersonApersonAperso', [
        'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
        'Sec-CH-UA-Mobile' => '?1',
    ]);

    expect(TrafficVisit::query()->sole())->device->toBe(TrafficDevice::Mobile)->spend->toBe($spend)->spend_basis->toBe($basis);
})->with([
    'priced as it came' => [null, '0.00500000', TrafficSpendBasis::Rate],
    'from the network’s figures' => ['0.010', '0.01000000', TrafficSpendBasis::Api],
]);

it('records the clicks of a paused source too, and nothing for a key no source has', function () {
    $this->source->forceFill(['status' => TrafficSourceStatus::Paused])->save();
    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-7');
    clickOn('/games/'.$this->slug.'?rg_src=nobody&cid=tag-8');

    expect(TrafficVisit::query()->count())->toBe(1);
});

it('brings an archived campaign back on its next click', function () {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'external_id' => '881204', 'status' => TrafficCampaignStatus::Archived, 'status_before_archive' => TrafficCampaignStatus::Active, 'archived_at' => now()]);

    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-9&campaign=881204');

    expect($campaign->fresh()->status)->toBe(TrafficCampaignStatus::Active)
        ->and($campaign->fresh()->archived_at)->toBeNull()
        ->and($campaign->events()->first()->summary)->toBe('Back from the archive: a click came in');
});

it('records nothing and gives no cookie while recording is off', function () {
    recordTraffic(false);

    $this->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])->get('/?rg_src=exo&cid=tag-10')->assertOk()->assertCookieMissing((string) config('games.visitor_cookie.name'));
    // A game's own result page gives no tracker cookie either; the game itself gives its players theirs as it always has.
    $this->actingAs(User::factory()->create())->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])->get('/games/'.$this->slug)->assertOk()->assertCookieMissing((string) config('games.visitor_cookie.name'));

    expect(TrafficVisit::query()->count())->toBe(0)
        // A page without rg_src the tracker would look at — a game, the feed — reads both switches from the cache, and asks the database nothing.
        ->and(captureCostOf('/games/'.$this->slug))->toBe(['reads' => 1, 'queries' => 0])
        ->and(captureCostOf('/'))->toBe(['reads' => 1, 'queries' => 0])
        // Any other page reads nothing at all.
        ->and(captureCostOf('/games/'.$this->draft['game']->id.'/preview'))->toBe(['reads' => 0, 'queries' => 0]);
});

it('costs a page without rg_src that is no game nothing', function () {
    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'traffic_')) {
            $queries++;
        }
    });

    $this->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])->get('/')->assertOk()->assertCookieMissing((string) config('games.visitor_cookie.name'));

    expect($queries)->toBe(0);
});

it('gives the site’s own pages neither a token nor a cookie while Record visits is on but Record site visits is off', function () {
    $post = Post::factory()->published()->create();
    $author = User::factory()->create(['username' => 'site_capture_author']);

    foreach (['/', '/posts/'.$post->id, '/u/'.$author->username, '/about', '/login'] as $path) {
        $page = $this->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])->get($path)->assertOk()->assertCookieMissing((string) config('games.visitor_cookie.name'));

        expect($page->getContent())->not->toContain('name="rg-arrival"');
    }

    expect(app(TrafficArrival::class)->pending())->toBeNull();
});

it('hands the site’s own pages their unpaid arrival while Record site visits is on, with the page’s route and path', function () {
    recordTraffic(site: true);
    $post = Post::factory()->published()->create();
    $author = User::factory()->create(['username' => 'site_capture_author']);
    $pages = [
        '/' => 'feed',
        '/posts/'.$post->id => 'posts.show',
        '/u/'.$author->username => 'profile.show',
        '/about' => 'pages.about',
        '/privacy' => 'pages.privacy',
        '/terms' => 'pages.terms',
        '/contact' => 'pages.contact',
        '/login' => 'login',
        '/register' => 'register',
    ];

    // A browser seen for the first time is given its cookie by the page.
    $this->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])->get('/about')->assertOk()->assertCookie((string) config('games.visitor_cookie.name'));

    foreach ($pages as $path => $route) {
        expect(printedArrival(clickOn($path)->assertOk()))->toBe([
            'channel' => 'direct',
            'origin' => null,
            'slug' => null,
            'result' => false,
            'route' => $route,
            'path' => $path,
            'host' => null,
            // A post's own page carries its post, counted opened when the page runs.
            ...($route === 'posts.show' ? ['post' => $post->id] : []),
        ]);
    }

    $settings = ProjectSettings::query()->find(1) ?? ProjectSettings::factory()->create();
    $settings->forceFill(['feature_flags' => [...($settings->feature_flags ?? []), 'show_saved_posts' => true]])->save();
    app(ProjectSettingsManager::class)->flush();
    $this->actingAs(User::factory()->create());

    expect(printedArrival(clickOn('/saved')->assertOk())['route'])->toBe('saved-posts.index')
        ->and(TrafficVisit::query()->count())->toBe(0);
});

it('draws the post asked for, and hands its arrival that post, when the page came from another post', function () {
    recordTraffic(site: true);
    $from = Post::factory()->published()->create(['title' => 'Glacier Blue Water']);
    $to = Post::factory()->published()->create(['title' => 'Foggy Hilltop Town']);

    // Where the referrer points is read without taking the route of the page asked for: a post's page from another's is the same route.
    $page = clickOn('/posts/'.$to->id, headers: ['Referer' => url('/posts/'.$from->id)])->assertOk()->assertSee('Foggy Hilltop Town')->assertDontSee('Glacier Blue Water');

    expect(printedArrival($page))->toMatchArray(['channel' => 'internal', 'origin' => 'post', 'route' => 'posts.show', 'path' => '/posts/'.$to->id, 'post' => $to->id]);
});

it('takes back the cookie of a site page that turns out to be no page', function () {
    recordTraffic(site: true);

    // Saved posts are a member's: a guest is sent to sign in, and the page that would have printed the token never comes.
    $this->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])->get('/saved')->assertRedirect(route('login'))->assertCookieMissing((string) config('games.visitor_cookie.name'));
    $this->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT])->get('/posts/999999')->assertNotFound()->assertCookieMissing((string) config('games.visitor_cookie.name'));
});

it('classes a visit to the feed by where it came from, and only a result page as a shared result', function (?string $referrer, string $channel, ?string $origin) {
    recordTraffic(site: true);
    $headers = $referrer === null ? [] : ['Referer' => str_replace('{site}', url('/'), $referrer)];

    expect(printedArrival(clickOn('/', headers: $headers)))->toMatchArray(['channel' => $channel, 'origin' => $origin, 'result' => false]);
})->with([
    'no referrer' => [null, 'direct', null],
    'a search' => ['https://www.google.com/search?q=taste', 'search', 'google'],
    'another site' => ['https://old.reddit.com/r/pics/', 'referral', 'old.reddit.com'],
    'the site itself' => ['{site}/u/someone', 'internal', 'profile'],
]);

it('classes a game’s result page reached from outside as a shared result, whatever the switches', function () {
    recordTraffic(site: true);
    $result = route('games.result', ['slug' => $this->slug, 'version' => 1, 'profile' => GameResultProfile::query()->value('key')], false);

    clickOn($result, headers: ['Referer' => 'https://www.reddit.com/r/pics/'])->assertOk();

    expect(ArrivalToken::read(app(TrafficArrival::class)->take(), browserHashOf('browserAbrowserAbrowserAbrowserAbrowserA'), now()->getTimestamp()))
        ->toMatchArray(['channel' => 'shared', 'origin' => 'reddit.com', 'result' => true, 'route' => 'games.result']);
});

it('leaves the administration, a game’s preview, share redirects and the health check alone', function () {
    recordTraffic(site: true);
    $post = Post::factory()->published()->create();
    $share = '/games/'.$this->slug.'/v1/'.GameResultProfile::query()->value('key').'/share/x';
    $fresh = fn () => $this->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT]);

    $fresh()->get('/posts/'.$post->id.'/share/x')->assertRedirect()->assertCookieMissing((string) config('games.visitor_cookie.name'));
    $fresh()->get('/up')->assertOk()->assertCookieMissing((string) config('games.visitor_cookie.name'));
    $this->actingAs(User::factory()->admin()->create());
    $preview = $fresh()->get('/games/'.$this->draft['game']->id.'/preview')->assertOk()->assertCookieMissing((string) config('games.visitor_cookie.name'));
    $panel = $fresh()->get('/admin')->assertOk()->assertCookieMissing((string) config('games.visitor_cookie.name'));
    // A shared result's way to its provider records the share for the game, which gives its players their cookie as it always has.
    auth()->logout();
    $fresh()->get($share)->assertRedirect();

    expect([$panel->getContent(), $preview->getContent()])->each->not->toContain('name="rg-arrival"')
        ->and(app(TrafficArrival::class)->pending())->toBeNull()
        // The tracker doesn't so much as read its switches for them.
        ->and(array_map(fn (string $path): array => captureCostOf($path), ['/posts/'.$post->id.'/share/x', $share, '/up', '/games/'.$this->draft['game']->id.'/preview']))
        ->each->toBe(['reads' => 0, 'queries' => 0]);
});

it('carries an unpaid arrival on a game page in its token, and records no visit for the page itself', function () {
    $page = clickOn('/games/'.$this->slug, headers: ['Referer' => 'https://www.google.co.uk/search?q=taste'])->assertOk();

    expect(TrafficVisit::query()->count())->toBe(0)
        ->and($page->getContent())->not->toContain('name="rg-arrival"')
        // The game's player took the token, with the page's own route and path: its first call comes from elsewhere.
        ->and(ArrivalToken::read(app(TrafficArrival::class)->take(), browserHashOf('browserAbrowserAbrowserAbrowserAbrowserA'), now()->getTimestamp()))->toBe([
            'channel' => 'search',
            'origin' => 'google',
            'slug' => $this->slug,
            'result' => false,
            'route' => 'games.play',
            'path' => '/games/'.$this->slug,
            'host' => 'google.co.uk',
        ]);
});

it('begins a session with every paid click, keeping its source, campaign, creative and the page it landed on', function () {
    $post = Post::factory()->published()->create();

    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=entry-1&campaign=5521904&rg_cr=video-03');
    clickOn('/posts/'.$post->id.'?rg_src=exo&cid=entry-2');
    clickOn('/games/'.$this->slug.'/v1/'.GameResultProfile::query()->value('key').'?rg_src=exo&cid=entry-3');

    [$onGame, $onPost, $onResult] = TrafficVisit::query()->orderBy('id')->get()->all();

    expect([$onGame->entry_visit_id, $onPost->entry_visit_id, $onResult->entry_visit_id])->toBe([$onGame->id, $onPost->id, $onResult->id])
        ->and([$onGame->source_key, $onPost->source_key, $onResult->source_key])->each->toBe('paid:'.$this->source->id)
        ->and($onGame->campaign_key)->toBe('paid:'.TrafficCampaign::query()->sole()->id)
        ->and($onPost->campaign_key)->toBeNull()
        ->and([$onGame->creative_key, $onPost->creative_key])->toBe(['video-03', null])
        ->and($onGame->landing_key)->toBe('games.play:'.$this->draft['game']->id)
        ->and($onPost->landing_key)->toBe('posts.show')
        // A result page names its game as the landing; the game played stays the one of a game's own page.
        ->and($onResult->landing_key)->toBe('games.result:'.$this->draft['game']->id)
        ->and($onResult->game_id)->toBeNull();
});

it('keeps the campaign tags a paid click’s address carries, its keys staying the source’s own', function () {
    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tagged-1&campaign=5521904&utm_source=exoclick&utm_medium=cpc&utm_campaign=Launch&utm_content=video-03&utm_term={keyword}');

    $visit = TrafficVisit::query()->sole();

    expect($visit->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term']))->toBe([
        'utm_source' => 'exoclick',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'Launch',
        'utm_content' => 'video-03',
        'utm_term' => null,
    ])
        ->and($visit->source_key)->toBe('paid:'.$this->source->id)
        ->and($visit->campaign_key)->toBe('paid:'.TrafficCampaign::query()->sole()->id)
        ->and($visit->creative_key)->toBeNull()
        ->and(printedArrival(clickOn('/?rg_src=exo&cid=tagged-2&utm_campaign=Launch')))->toBe(['visit' => TrafficVisit::query()->latest('id')->value('id')]);
});

it('carries the campaign tags of an unpaid arrival’s address in its token, as the visit will keep them', function () {
    recordTraffic(site: true);

    expect(printedArrival(clickOn('/?utm_source=%20reddit%20&utm_campaign=Launch%0Aweek&utm_content={creative}&rg_cr=x')))->toBe([
        'channel' => 'direct',
        'origin' => null,
        'slug' => null,
        'result' => false,
        'route' => 'feed',
        'path' => '/',
        'host' => null,
        'tags' => ['utm_source' => 'reddit', 'utm_campaign' => 'Launchweek'],
    ])
        ->and(printedArrival(clickOn('/?utm_term=%7Bkeyword%7D')))->not->toHaveKey('tags');
});

it('keeps a token within what is read back, however long the tags, cutting them to one length that fits', function () {
    $hash = browserHashOf('browserAbrowserAbrowserAbrowserAbrowserA');
    $at = now()->getTimestamp();
    $host = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 58).'.com';
    // As long as a page's own arrival gets: the longest host, as the origin too, a long slug and the longest path.
    $page = ['channel' => 'referral', 'origin' => $host, 'slug' => str_repeat('s', 100), 'result' => false, 'route' => 'games.player-result', 'path' => '/'.str_repeat('p', 254), 'host' => $host];
    $ordinary = ['channel' => 'search', 'origin' => 'google', 'slug' => null, 'result' => false, 'route' => 'feed', 'path' => '/', 'host' => 'google.com'];
    $longest = [
        'utm_source' => str_repeat('s', 120),
        'utm_medium' => str_repeat('m', 120),
        'utm_campaign' => str_repeat('🔥', 120),
        'utm_content' => str_repeat('я', 120),
        'utm_term' => str_repeat('"', 120),
    ];

    $token = ArrivalToken::make([...$page, 'tags' => $longest], $hash, $at);
    $read = ArrivalToken::read($token, $hash, $at);

    expect(strlen($token))->toBeLessThanOrEqual(ArrivalToken::MAX_LENGTH)
        ->and(array_diff_key($read, ['tags' => true]))->toBe($page)
        ->and(array_keys($read['tags']))->toBe(array_keys($longest))
        // Each kept as many characters as the others, however many bytes they take: how each begins.
        ->and(array_unique(array_map(mb_strlen(...), $read['tags'])))->toHaveCount(1)
        ->and(mb_strlen($read['tags']['utm_source']))->toBeGreaterThan(0)->toBeLessThan(120);

    foreach ($read['tags'] as $key => $tag) {
        expect($longest[$key])->toStartWith($tag);
    }

    // On an ordinary page, a short tag keeps all it has while the longer ones are cut, the longest bytes and all.
    $token = ArrivalToken::make([...$ordinary, 'tags' => [...$longest, 'utm_source' => 'reddit']], $hash, $at);
    $read = ArrivalToken::read($token, $hash, $at);

    expect(strlen($token))->toBeLessThanOrEqual(ArrivalToken::MAX_LENGTH)
        ->and($read['tags']['utm_source'])->toBe('reddit')
        ->and(mb_strlen($read['tags']['utm_campaign']))->toBeGreaterThan(6)->toBeLessThan(120)
        ->and(mb_strlen($read['tags']['utm_medium']))->toBe(mb_strlen($read['tags']['utm_campaign']));

    // Its tags fit as they are when they take one byte a character, at their longest.
    $whole = array_map(fn (string $tag): string => str_repeat('x', 120), $longest);

    expect(ArrivalToken::read(ArrivalToken::make([...$ordinary, 'tags' => $whole], $hash, $at), $hash, $at))->toBe([...$ordinary, 'tags' => $whole]);

    // A page that leaves no room for a single character of a tag carries none.
    $full = [...$page, 'path' => '/'.str_repeat('p', 370)];
    $token = ArrivalToken::make([...$full, 'tags' => $whole], $hash, $at);

    expect(strlen($token))->toBeLessThanOrEqual(ArrivalToken::MAX_LENGTH)
        ->and(ArrivalToken::read($token, $hash, $at))->toBe($full);
});

it('draws the page whatever goes wrong while recording the click', function () {
    app()->bind(RecordPaidClickAction::class, fn () => throw new RuntimeException('broken'));

    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-11')->assertOk();

    expect(TrafficVisit::query()->count())->toBe(0);
});

it('records nothing for a HEAD request', function () {
    $this->withCookie((string) config('games.visitor_cookie.name'), 'browserAbrowserAbrowserAbrowserAbrowserA')
        ->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT, 'Accept-Language' => 'en-GB', 'Sec-CH-UA' => '"Chromium";v="131"'])
        ->call('HEAD', '/games/'.$this->slug.'?rg_src=exo&cid=tag-12')
        ->assertOk();

    expect(TrafficVisit::query()->count())->toBe(0);
});

it('gives an account credited to a click no browser has run the device of the browser the click passes to', function () {
    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-device', 'prefetchprefetchprefetchprefetchprefetch');
    $click = TrafficVisit::query()->sole();
    $credited = AccountAttribution::factory()->confirmed()->create(['credited_visit_id' => $click->id]);
    $another = AccountAttribution::factory()->confirmed()->create(['credited_visit_id' => TrafficVisit::factory()->create(['device' => 'desktop'])->id]);

    expect($credited->fresh()->credited_device)->toBe(TrafficDevice::Desktop);

    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-device', 'personApersonApersonApersonApersonAperso', [
        'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
        'Sec-CH-UA-Mobile' => '?1',
    ]);

    expect($click->fresh()->device)->toBe(TrafficDevice::Mobile)
        ->and($credited->fresh()->credited_device)->toBe(TrafficDevice::Mobile)
        ->and($another->fresh()->credited_device)->toBe(TrafficDevice::Desktop);
});

it('leaves an account credited to a click no browser has run, written without its copies, for the copy step to fill whole once the click passes', function () {
    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-copy', 'prefetchprefetchprefetchprefetchprefetch');
    $click = TrafficVisit::query()->sole();
    // As the release before the copies wrote it, while a deploy ran between migrating and switching over.
    $credited = AccountAttribution::factory()->confirmed()->create(['credited_visit_id' => $click->id, 'credited_source_key' => null, 'credited_device' => null]);

    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-copy', 'personApersonApersonApersonApersonAperso', [
        'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
        'Sec-CH-UA-Mobile' => '?1',
    ]);
    app(CopyCreditedKeysAction::class)->handle();

    expect($click->fresh()->source_key)->not->toBeNull()
        ->and($credited->fresh())
        ->credited_source_key->toBe($click->fresh()->source_key)
        ->credited_device->toBe(TrafficDevice::Mobile);
});
