<?php

use App\Actions\Analytics\SaveTrackingLinkAction;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Models\TrafficCampaign;
use App\Models\TrafficSegment;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
 * The tabs of Growth → Analytics slow to read on a busy site keep their
 * figures for ten minutes once read: the page says when they were read and
 * offers Refresh; older figures and Refresh read them again, and a change the
 * page made itself reads everything kept again, on whichever tab, filter or
 * details come next. They are kept for each administrator and each filter
 * apart, a filter the tab ignores reading nothing again, and a tab quick to
 * read is always read afresh.
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

it('keeps the figures for each administrator and each filter apart, and never a tab quick to read', function () {
    analyticsVisit('ann', ['device' => 'mobile']);
    $overview = fn (array $query = []): array => Livewire::withQueryParams(['period' => '7d', ...$query])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data');

    expect($overview()['kpis'][0]['value'])->toBe('1');

    analyticsVisit('bob', ['device' => 'mobile']);

    // Kept for this administrator alone: another reads them for themselves.
    expect($overview()['kpis'][0]['value'])->toBe('1');
    $this->actingAs(User::factory()->admin()->create());
    expect($overview()['kpis'][0]['value'])->toBe('2');

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
