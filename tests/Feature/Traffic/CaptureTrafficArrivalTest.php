<?php

use App\Actions\Traffic\RecordPaidClickAction;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Enums\TrafficSourceStatus;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\TrafficVisitDetail;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Traffic\Capture\ArrivalToken;
use Carbon\CarbonImmutable;

/**
 * A paid click is recorded when its page is asked for, before anything is
 * drawn: on any page of the site, before the guest-viewing check sends the
 * visitor to sign in, once per network click id; and a page without rg_src
 * that is no game costs nothing.
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

it('hands the page a token of the click, which the layout prints on any page but a game', function () {
    $post = Post::factory()->published()->create();

    $page = clickOn('/posts/'.$post->id.'?rg_src=exo&cid=tag-2')->assertOk();
    preg_match('/<meta name="rg-arrival" content="([^"]+)">/', $page->getContent(), $meta);

    expect(ArrivalToken::read($meta[1] ?? null, browserHashOf('browserAbrowserAbrowserAbrowserAbrowserA'), now()->getTimestamp()))->toBe(['visit' => TrafficVisit::query()->sole()->id]);
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

    expect(TrafficVisit::query()->count())->toBe(0);
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

it('carries an unpaid arrival on a game page in its token, and records no visit for the page itself', function () {
    $page = clickOn('/games/'.$this->slug, headers: ['Referer' => 'https://www.google.com/'])->assertOk();

    expect(TrafficVisit::query()->count())->toBe(0)
        ->and($page->getContent())->not->toContain('name="rg-arrival"');
});

it('draws the page whatever goes wrong while recording the click', function () {
    app()->bind(RecordPaidClickAction::class, fn () => throw new RuntimeException('broken'));

    clickOn('/games/'.$this->slug.'?rg_src=exo&cid=tag-11')->assertOk();

    expect(TrafficVisit::query()->count())->toBe(0);
});

it('records nothing for a HEAD request', function () {
    $this->call('HEAD', '/games/'.$this->slug.'?rg_src=exo&cid=tag-12');

    expect(TrafficVisit::query()->count())->toBe(0);
});
