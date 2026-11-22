<?php

use App\Actions\Analytics\SaveTrackingLinkAction;
use App\Enums\GameStatus;
use App\Filament\Pages\Analytics\AnalyticsReadCache;
use App\Filament\Pages\Analytics\AnalyticsTab;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Models\Game;
use App\Models\TrafficCampaign;
use App\Models\TrafficSegment;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/*
 * The tabs of Growth → Analytics slow to read on a busy site keep their
 * figures for ten minutes once read: the page says when they were read and
 * offers Refresh; older figures and Refresh read them again, and a change the
 * page made itself reads everything kept again, on whichever tab, filter or
 * details come next. They are kept for everyone who may open the page, read
 * once however many ask at a time, and for each filter apart, a filter the
 * tab ignores reading nothing again; a tab quick to read is always read
 * afresh.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    TrafficSetting::current()->forceFill(['capture_enabled' => true, 'site_capture_enabled' => true])->save();
    TrafficSetting::forgetCaptureFlags();
});

it('keeps a slow tab’s figures for ten minutes once read, saying when, and reads them again on Refresh or once older', function () {
    analyticsVisit('ann');
    $page = Livewire::withQueryParams(['period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load');
    $visitors = fn (): string => $page->viewData('data')['kpis'][0]['value'];

    expect(GrowthAnalyticsPage::READ_CACHED)->toContain('overview')
        ->and($visitors())->toBe('1');
    $page->assertSeeText('Figures read 12:00 UTC')->assertSeeText('Refresh');

    // A visit recorded since, minutes later: the figures as read.
    analyticsVisit('bob');
    $this->travel(5)->minutes();
    $page->call('$refresh');

    expect($visitors())->toBe('1');
    $page->assertSeeText('Figures read 12:00 UTC');

    // Refresh reads them now.
    $page->call('refreshFigures');

    expect($visitors())->toBe('2');
    $page->assertSeeText('Figures read 12:05 UTC');

    // Figures older than ten minutes are read again by themselves.
    analyticsVisit('cid');
    $this->travel(9)->minutes();
    $page->call('$refresh');
    expect($visitors())->toBe('2');

    $this->travel(2)->minutes();
    $page->call('$refresh');

    expect($visitors())->toBe('3');
    $page->assertSeeText('Figures read 12:16 UTC');
});

it('keeps the figures for every administrator and each filter apart, and never a tab quick to read', function () {
    analyticsVisit('ann', ['device' => 'mobile']);
    $overview = fn (array $query = []): array => Livewire::withQueryParams(['period' => '7d', ...$query])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data');

    expect($overview()['kpis'][0]['value'])->toBe('1');

    analyticsVisit('bob', ['device' => 'mobile']);

    // Kept for everyone who may open the page: another administrator gets them as read.
    expect($overview()['kpis'][0]['value'])->toBe('1');
    $this->actingAs(User::factory()->admin()->create());
    expect($overview()['kpis'][0]['value'])->toBe('1');

    // Another filter, another read.
    $this->actingAs($this->admin);
    expect($overview(['device' => 'mobile'])['kpis'][0]['value'])->toBe('2')
        ->and($overview(['source' => 'direct'])['kpis'][0]['value'])->toBe('2')
        ->and($overview()['kpis'][0]['value'])->toBe('1');

    // A tab quick to read says nothing of when, and reads every time.
    expect(GrowthAnalyticsPage::READ_CACHED)->not->toContain('journeys');
    Livewire::withQueryParams(['tab' => 'journeys'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertViewHas('readAt', null)
        ->assertDontSeeText('Figures read');
});

it('reads the figures again once the page saved, deleted or brought back a segment, or saved a tracking link', function (string $event, array $params) {
    analyticsVisit('ann');
    $page = Livewire::withQueryParams(['period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load');

    analyticsVisit('bob');
    $this->travel(3)->minutes();
    $page->dispatch($event, ...$params);

    expect($page->viewData('data')['kpis'][0]['value'])->toBe('2')
        ->and($page->viewData('readAt'))->toBe('12:03');
})->with([
    'segment saved' => ['traffic-segment-saved', ['message' => 'Segment saved']],
    'segment deleted' => ['traffic-segment-deleted', ['id' => null, 'name' => 'Phones']],
    'tracking link saved' => ['tracking-link-saved', ['id' => null, 'message' => 'Link saved']],
]);

it('reads a link’s details again once the page saved the link, though they were kept before its editor opened', function () {
    $fields = ['destination' => 'page', 'page' => '/', 'source' => 'reddit', 'medium' => 'organic_social', 'campaign' => 'versus_launch', 'content' => 'post_01'];
    $link = app(SaveTrackingLinkAction::class)->handle($this->admin, null, [...$fields, 'name' => 'Old name']);
    $page = Livewire::withQueryParams(['tab' => 'links', 'period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load')->call('showLink', $link->id);

    expect($page->viewData('data')['detail']['name'])->toBe('Old name');

    // Edit from the details, the link renamed and saved: the details open again, read afresh.
    $page->call('editLink', $link->id);
    $this->travel(2)->minutes();
    app(SaveTrackingLinkAction::class)->handle($this->admin, $link->id, [...$fields, 'name' => 'New name']);
    $page->dispatch('tracking-link-saved', id: $link->id)->set('show', (string) $link->id);

    expect($page->viewData('data')['detail']['name'])->toBe('New name')
        ->and($page->viewData('readAt'))->toBe('12:02');
});

it('reads a segment’s drawer again once the page saved a segment, though it was kept before the save', function () {
    $exo = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    analyticsClick('ann', TrafficCampaign::factory()->create(['source_id' => $exo->id]), ['site_ref' => '1182']);
    $page = Livewire::withQueryParams(['tab' => 'campaigns', 'period' => '7d', 'source' => 'paid:'.$exo->id])->test(GrowthAnalyticsPage::class)->call('load')->call('addSegment');

    expect(array_column($page->viewData('data')['segments']['rows'], 'kind'))->toBe(['all']);

    // Saved in the drawer, then Add segment again: the new segment is on the card.
    $this->travel(2)->minutes();
    TrafficSegment::factory()->create(['source_id' => $exo->id, 'name' => 'Phones', 'rules' => [['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']]]]);
    $page->dispatch('traffic-segment-saved', message: 'Segment saved')->call('addSegment');

    expect(array_column($page->viewData('data')['segments']['rows'], 'name'))->toContain('Phones')
        ->and($page->viewData('readAt'))->toBe('12:02');
});

it('reads nothing again for a filter the open tab ignores', function (string $tab, string $filter, string $value) {
    analyticsVisit('ann', ['account_at' => '2026-11-15 10:05:00', 'signup_at' => '2026-11-15 10:04:00']);
    $page = Livewire::withQueryParams(['tab' => $tab, 'period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load');
    $first = $page->viewData('data');

    analyticsVisit('bob', ['account_at' => '2026-11-16 10:05:00', 'signup_at' => '2026-11-16 10:04:00']);
    $this->travel(4)->minutes();
    $page->set($filter, $value);

    expect($page->viewData('data'))->toEqual($first)
        ->and($page->viewData('readAt'))->toBe('12:00');
})->with([
    'Retention, a source' => ['retention', 'source', 'direct'],
    'Experiments, a device' => ['experiments', 'device', 'mobile'],
    'Experiments, a period' => ['experiments', 'period', '30d'],
]);

it('offers the sources as kept whichever source is chosen: the source is no part of what they are read of', function () {
    analyticsVisit('ann', ['source_key' => 'reddit:organic']);
    $page = Livewire::withQueryParams(['period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load');

    analyticsVisit('bob', ['source_key' => 'google:organic']);
    $page->set('source', 'reddit:organic');

    expect(array_column($page->viewData('sourceOptions'), 'value'))->toBe(['', 'reddit:organic']);
});

it('draws each tab it keeps the same from what was kept, through a store that serializes it', function (string $tab) {
    config(['cache.stores.array.serialize' => true]);
    Cache::forgetDriver('array');
    analyticsVisit('ann', ['country' => 'DE', 'city' => 'Berlin', 'landing_key' => 'feed', 'source_key' => 'reddit:organic', 'campaign_key' => 'tag:spring', 'utm_campaign' => 'spring', 'utm_source' => 'reddit']);
    analyticsVisit('bob', ['country' => 'US', 'landing_key' => 'posts.show', 'account_at' => '2026-11-15 10:05:00', 'signup_at' => '2026-11-15 10:04:00']);
    // An account whose first week is over, so Retention has shares to draw.
    retentionAccount('2026-11-02 10:00:00', [1, 7]);
    $read = fn () => Livewire::withQueryParams(['tab' => $tab, 'period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load');

    $first = $read();
    $this->travel(1)->minutes();
    $kept = $read();

    // Plain values only: the store gives back no object.
    expect(GrowthAnalyticsPage::READ_CACHED)->toContain($tab)
        ->and($kept->viewData('data'))->toEqual($first->viewData('data'))
        ->and(array_filter(Arr::dot($first->viewData('data')), 'is_object'))->toBe([])
        ->and($kept->viewData('readAt'))->toBe('12:00');
    $kept->assertSeeText('Figures read 12:00 UTC');
})->with(GrowthAnalyticsPage::READ_CACHED);

it('keeps the sources and campaigns the filters offer the same way, read again on Refresh', function () {
    analyticsVisit('ann', ['source_key' => 'reddit:organic', 'campaign_key' => 'tag:spring', 'utm_campaign' => 'spring']);
    $page = Livewire::withQueryParams(['period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load');
    $offered = fn (string $filter): array => array_column($page->viewData($filter), 'value');

    expect($offered('sourceOptions'))->toBe(['', 'reddit:organic'])
        ->and($offered('campaignOptions'))->toBe(['', 'tag:spring']);

    analyticsVisit('bob', ['source_key' => 'google:organic', 'campaign_key' => 'tag:autumn', 'utm_campaign' => 'autumn']);
    $page->call('$refresh');

    expect($offered('sourceOptions'))->toBe(['', 'reddit:organic'])
        ->and($offered('campaignOptions'))->toBe(['', 'tag:spring']);

    $page->call('refreshFigures');

    expect($offered('sourceOptions'))->toContain('reddit:organic', 'google:organic')
        ->and($offered('campaignOptions'))->toContain('tag:spring', 'tag:autumn');
});

it('reads a tab once for every administrator: the second to open it reads none of its figures, and Refresh reads them for everyone', function () {
    analyticsVisit('ann');
    $reads = 0;
    app()->resolving(AnalyticsTab::class, function () use (&$reads): void {
        $reads++;
    });
    $open = fn () => Livewire::withQueryParams(['period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load');

    $first = $open();

    expect($reads)->toBe(1);

    analyticsVisit('bob');
    $this->travel(2)->minutes();
    $this->actingAs(User::factory()->admin()->create());
    DB::enableQueryLog();
    $second = $open();
    $statements = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // The visits are asked only when the first was recorded: nothing of the tab's own.
    expect($reads)->toBe(1)
        ->and($second->viewData('data'))->toEqual($first->viewData('data'))
        ->and($second->viewData('readAt'))->toBe('12:00')
        ->and($statements->filter(fn (string $sql): bool => preg_match('/traffic_visits|analytics_visits|analytics_cells|analytics_game_days/', $sql) === 1 && preg_match('/min\(.arrived_at.\)/', $sql) !== 1)->values()->all())->toBe([]);

    // Refresh reads them now, for everyone.
    $second->call('refreshFigures');
    $this->actingAs($this->admin);
    $third = $open();

    expect($reads)->toBe(2)
        ->and($third->viewData('data')['kpis'][0]['value'])->toBe('2')
        ->and($third->viewData('readAt'))->toBe('12:02');
});

it('still refuses whoever may not manage traffic sources, though figures are kept', function () {
    analyticsVisit('ann');
    $page = Livewire::withQueryParams(['period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load');

    $this->actingAs(User::factory()->moderator()->create())->get(GrowthAnalyticsPage::getUrl(['period' => '7d']))->assertForbidden();
    $page->call('$refresh')->assertForbidden();
    $this->actingAs(User::factory()->admin()->limited()->create())->get(GrowthAnalyticsPage::getUrl(['period' => '7d']))->assertForbidden();
});

it('keeps a tracking link’s details apart for whoever may not manage games, which lead to its game only for those who may', function () {
    $game = Game::factory()->status(GameStatus::Live)->create();
    $link = app(SaveTrackingLinkAction::class)->handle($this->admin, null, ['destination' => 'game', 'game' => (string) $game->id, 'source' => 'reddit', 'medium' => 'organic_social', 'campaign' => 'versus_launch', 'content' => 'post_01', 'name' => 'To the game']);
    $details = fn (): array => Livewire::withQueryParams(['tab' => 'links', 'period' => '7d', 'show' => (string) $link->id])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data')['detail'];

    expect($details()['game'])->not->toBeNull();

    Gate::define('manage-games', fn (): bool => false);
    $this->actingAs(User::factory()->admin()->create());

    expect($details()['game'])->toBeNull();

    // The list alone shows nobody anything of their own: kept for everyone.
    $reads = 0;
    app()->resolving(AnalyticsTab::class, function () use (&$reads): void {
        $reads++;
    });
    Livewire::withQueryParams(['tab' => 'links', 'period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load');
    $this->actingAs($this->admin);
    Livewire::withQueryParams(['tab' => 'links', 'period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load');

    expect($reads)->toBe(1);
});

it('keeps Games apart for whoever may not manage games, whose traffic periods lead to their results only for those who may', function () {
    Game::factory()->status(GameStatus::Live)->create();
    $reads = 0;
    app()->resolving(AnalyticsTab::class, function () use (&$reads): void {
        $reads++;
    });
    $open = fn () => Livewire::withQueryParams(['tab' => 'games', 'period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load');

    $open();
    $open();

    expect($reads)->toBe(1);

    Gate::define('manage-games', fn (): bool => false);
    $this->actingAs(User::factory()->admin()->create());
    $open();

    expect($reads)->toBe(2);
});

it('keeps the figures by the site’s address the page was asked at, every address in them starting with it', function () {
    analyticsVisit('ann');
    $open = fn () => Livewire::withQueryParams(['tab' => 'geo', 'period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load');

    expect($open()->viewData('data')['journeys'])->toStartWith(url('/').'/')
        ->and(url('/'))->not->toBe('https://admin.example');

    // As a request to another of the site's addresses builds every address.
    URL::useOrigin('https://admin.example');
    URL::forceScheme('https');

    expect($open()->viewData('data')['journeys'])->toStartWith('https://admin.example/');
});

it('drops what is kept for everyone once any administrator changed a segment or tracking link', function () {
    analyticsVisit('ann');
    $open = fn () => Livewire::withQueryParams(['period' => '7d'])->test(GrowthAnalyticsPage::class)->call('load');
    $open();

    analyticsVisit('bob');
    $this->actingAs(User::factory()->admin()->create());
    $open()->dispatch('traffic-segment-saved', message: 'Segment saved');
    $this->actingAs($this->admin);

    expect($open()->viewData('data')['kpis'][0]['value'])->toBe('2');
});

it('waits for figures another request is reading, and takes them', function () {
    $key = Closure::bind(fn (): string => AnalyticsReadCache::key('overview', ['days' => ['2026-11-12', '2026-11-18']]), null, AnalyticsReadCache::class)();
    $other = Cache::lock($key.':reading', 120);
    $other->get();
    Sleep::fake();
    // The other request finishes while this one waits.
    Sleep::whenFakingSleep(function () use ($other, $key): void {
        Cache::put($key, ['at' => '2026-11-18T11:59:58+00:00', 'data' => ['theirs']], 600);
        $other->release();
    });

    $kept = app(AnalyticsReadCache::class)->remember('overview', ['days' => ['2026-11-12', '2026-11-18']], false, fn (): array => ['mine']);

    expect($kept['data'])->toBe(['theirs'])
        ->and($kept['readAt']->format('H:i:s'))->toBe('11:59:58');
    Sleep::assertSleptTimes(1);
});

it('reads the figures itself once it waited as long as it may, and keeps them; Refresh never waits', function () {
    $state = ['days' => ['2026-11-12', '2026-11-18']];
    $key = Closure::bind(fn (): string => AnalyticsReadCache::key('overview', $state), null, AnalyticsReadCache::class)();
    Cache::lock($key.':reading', 120)->get();
    Sleep::fake(syncWithCarbon: true);

    $read = app(AnalyticsReadCache::class)->remember('overview', $state, false, fn (): array => ['mine']);

    // It waited as long as it may, and no longer.
    expect($read['data'])->toBe(['mine'])
        ->and(CarbonImmutable::parse('2026-11-18 12:00:00')->diffInSeconds(now()))->toBeGreaterThanOrEqual(AnalyticsReadCache::WAIT_SECONDS - 1)->toBeLessThanOrEqual(AnalyticsReadCache::WAIT_SECONDS + 1)
        ->and(app(AnalyticsReadCache::class)->remember('overview', $state, false, fn (): array => ['again'])['data'])->toBe(['mine']);

    Sleep::fake();
    $refreshed = app(AnalyticsReadCache::class)->remember('overview', $state, true, fn (): array => ['refreshed']);

    expect($refreshed['data'])->toBe(['refreshed']);
    Sleep::assertNeverSlept();
});
