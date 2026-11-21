<?php

use App\Actions\Analytics\DeleteTrafficSegmentAction;
use App\Actions\Analytics\RestoreTrafficSegmentAction;
use App\Actions\Analytics\SaveTrafficSegmentAction;
use App\Enums\GameStatus;
use App\Enums\UserRole;
use App\Exceptions\Traffic\CannotManageTrafficException;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Livewire\Admin\TrafficSegmentEditor;
use App\Models\Game;
use App\Models\TrackingLink;
use App\Models\TrafficCampaign;
use App\Models\TrafficSegment;
use App\Models\TrafficSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Analytics → Campaigns → a paid source's segments: All of its clicks, each
 * segment with its rule as chips and when it was made, and the visits of no
 * segment, with visitors, starts, accounts, accounts per new visitor toned by
 * the verdict, activated users, what an account cost and the verdict against
 * the source; the drawer that makes and edits one, previewing what its
 * conditions catch, a campaign only the source's own, saved only at the
 * version it was drawn from; a delete that only marks it, with Undo; at most
 * a few a source; and nobody but an administrator who may manage traffic
 * sources.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    // The figures as they are at each read: the read cache has tests of its own (AnalyticsReadCacheTest).
    config(['analytics.read_cache_minutes' => 0]);
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    // Small denominators, so a handful of people make a verdict.
    config(['analytics.segment_min_visitors' => 10, 'analytics.segment_min_source_accounts' => 2]);
    $this->exo = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    $this->campaign = TrafficCampaign::factory()->create(['source_id' => $this->exo->id, 'name' => 'US popunder', 'external_id' => '881204']);
});

/** Paid clicks of a campaign by as many people as given, the first so many making an account, ten cents each. */
function segmentClicks(TrafficCampaign $campaign, string $prefix, int $people, array $attributes = [], int $accounts = 0): void
{
    foreach (range(1, $people) as $n) {
        analyticsClick($prefix.'-'.$n, $campaign, [
            'spend' => '0.10',
            ...($n <= $accounts ? ['signup_at' => '2026-11-05 11:00:00', 'account_at' => '2026-11-05 11:00:00'] : []),
            ...$attributes,
        ]);
    }
}

/**
 * ExoClick's people: twenty on site 1182 by mobile, four of them accounts,
 * one activated; twenty on site 2210 by desktop, none, ten of them starting
 * a game; ten on site 3300 by mobile, one; three on site 4400 by mobile.
 */
function segmentsCardFixture(TrafficCampaign $campaign): void
{
    segmentClicks($campaign, 'a', 20, ['site_ref' => '1182', 'device' => 'mobile'], 4);
    analyticsClick('a-1', $campaign, ['site_ref' => '1182', 'device' => 'mobile', 'activated_at' => '2026-11-07 09:00:00']);
    segmentClicks($campaign, 'b', 10, ['site_ref' => '2210', 'device' => 'desktop', 'started_at' => '2026-11-15 10:01:00']);
    segmentClicks($campaign, 'e', 10, ['site_ref' => '2210', 'device' => 'desktop']);
    segmentClicks($campaign, 'c', 10, ['site_ref' => '3300', 'device' => 'mobile'], 1);
    segmentClicks($campaign, 'd', 3, ['site_ref' => '4400', 'device' => 'mobile']);
}

/** A segment of a source as an administrator saved it. */
function segmentOf(TrafficSource $source, string $name, array $rules, string $created = '2026-11-18 12:00:00'): TrafficSegment
{
    return TrafficSegment::factory()->create(['source_id' => $source->id, 'name' => $name, 'rules' => $rules, 'created_at' => $created, 'updated_at' => $created]);
}

/** The open Campaigns tab's segments card, read as the page reads it once it is in a browser. */
function analyticsSegments(array $query): ?array
{
    return Livewire::withQueryParams(['tab' => 'campaigns', ...$query])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data')['segments'];
}

/** A row of the card as text: name, what it is, its rule, its figures and its verdict. */
function segmentLine(array $row): string
{
    return implode(' | ', [
        $row['name'], $row['meta'], implode(' & ', $row['chips']),
        ...array_map(fn (mixed $cell): string => is_array($cell) ? $cell['value'].($cell['tone'] === null ? '' : ' ('.$cell['tone'].')') : $cell, array_values($row['cells'])),
        $row['verdict']['label'] ?? '-',
    ]);
}

/** The drawer of a source's segment — a new one with none named — on the page's last 30 days. */
function segmentEditor(TrafficSource $source, ?TrafficSegment $segment = null): mixed
{
    return Livewire::test(TrafficSegmentEditor::class, ['sourceId' => $source->id, 'segmentId' => $segment->id ?? 0, 'period' => '30d']);
}

it('lists a paid source’s segments under the paid sources: All, each with its rule, and the visits of no segment', function () {
    segmentsCardFixture($this->campaign);
    segmentOf($this->exo, 'Publisher 1182', [['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']]], '2026-11-05 10:00:00');
    segmentOf($this->exo, 'Desktop', [['parameter' => 'device', 'operator' => 'in', 'values' => ['desktop']]]);
    segmentOf($this->exo, 'Small site', [['parameter' => 'site', 'operator' => 'in', 'values' => ['3300']], ['parameter' => 'device', 'operator' => 'in', 'values' => ['mobile']]]);
    segmentOf($this->exo, 'Germany', [['parameter' => 'country', 'operator' => 'in', 'values' => ['DE']]], '2025-12-01 10:00:00');
    segmentOf($this->exo, 'Gone', [['parameter' => 'site', 'operator' => 'in', 'values' => ['4400']]])->delete();

    $segments = analyticsSegments(['source' => 'paid:'.$this->exo->id]);

    // Accounts per new visitor: 5 of 53 for the source, so Strong from 11.8% and a block candidate to 4.7%.
    expect(array_map(segmentLine(...), $segments['rows']))->toBe([
        'All ExoClick tests | Every visit of ExoClick tests |  | 53 | 10 | 5 | 9.4% | 1 | $1.06 | -',
        'Germany | Created 01 Dec 2025 | Country is DE | 0 | 0 | 0 | — | 0 | — | Too little data',
        'Publisher 1182 | Created 05 Nov | Site is 1182 | 20 | 0 | 4 | 20.0% (success) | 1 | $0.500 | Strong',
        'Desktop | Created 18 Nov | Device is Desktop | 20 | 10 | 0 | 0.0% (danger) | 0 | — | Block candidate',
        'Small site | Created 18 Nov | Site is 3300 & Device is Mobile | 10 | 0 | 1 | 10.0% | 0 | $1.00 | Average',
        // The three people on site 4400: too few for a rate.
        'Not in any segment | Visits no segment matches |  | 3 | 0 | 0 | — | 0 | — | -',
    ])
        ->and(array_column($segments['rows'], 'edit'))->toBe([null, ...TrafficSegment::query()->orderBy('created_at')->orderBy('id')->pluck('id')->all(), null])
        ->and($segments['rows'][2]['cells']['rate']['title'])->toBe('4 of 20 new visitors')
        ->and($segments['title'])->toBe('ExoClick tests segments')
        ->and($segments['settings'])->toBe(TrafficSourcesPage::getUrl(['tab' => 'sources', 'edit' => $this->exo->id]))
        ->and($segments['report'])->toBe(TrafficSourcesPage::getUrl(['source' => $this->exo->id]))
        ->and($segments['foot'])->toBe([
            'Segments can overlap, so their rows don’t add up to All ExoClick tests. Verdicts compare each segment with all ExoClick tests traffic: 9.4% accounts per new visitor.',
            'A rate shows “—”, and a segment has too little data for a verdict, until it has 10 new visitors.',
        ]);

    $page = Livewire::withQueryParams(['tab' => 'campaigns', 'source' => 'paid:'.$this->exo->id])->test(GrowthAnalyticsPage::class)->call('load');

    expect($page->viewData('data')['paidSources']['rows'][0]['note'])->toBe('Ad network · spend: price in each click · 1 campaign · 4 segments');
    $page->assertSee('ExoClick tests segments')
        ->assertSee('Block a site or zone in Traffic sources → Report')
        ->assertSeeHtml('editSegment('.$segments['rows'][2]['edit'].')');
});

it('reads the period and device, says the campaign doesn’t narrow them, and that verdicts wait for the source’s accounts', function () {
    segmentsCardFixture($this->campaign);
    segmentOf($this->exo, 'Publisher 1182', [['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']]]);

    $desktop = analyticsSegments(['source' => 'paid:'.$this->exo->id, 'device' => 'desktop', 'campaign' => 'paid:'.$this->campaign->id]);

    expect(array_map(segmentLine(...), $desktop['rows']))->toBe([
        'All ExoClick tests | Every visit of ExoClick tests |  | 20 | 10 | 0 | 0.0% | 0 | — | -',
        'Publisher 1182 | Created 18 Nov | Site is 1182 | 0 | 0 | 0 | — | 0 | — | Too little data',
        'Not in any segment | Visits no segment matches |  | 20 | 10 | 0 | 0.0% | 0 | — | -',
    ])
        ->and($desktop['foot'])->toBe([
            'Segments can overlap, so their rows don’t add up to All ExoClick tests. Verdicts wait until ExoClick tests has 2 accounts in these days.',
            'A rate shows “—”, and a segment has too little data for a verdict, until it has 10 new visitors.',
            'Segments read the period and device; the campaign chosen doesn’t narrow them.',
        ]);
});

it('draws no segments until a paid source is chosen, and says so while the source has none', function () {
    segmentsCardFixture($this->campaign);
    analyticsVisit('reader', ['source_key' => 'reddit:organic']);
    DB::table('traffic_visits')->update(['spend' => 0]);

    expect(analyticsSegments([]))->toBeNull()
        ->and(analyticsSegments(['source' => 'reddit:organic']))->toBeNull();

    $none = analyticsSegments(['source' => 'paid:'.$this->exo->id]);

    expect(array_column($none['rows'], 'kind'))->toBe(['all'])
        ->and($none['count'])->toBe(0)
        ->and($none['foot'][0])->toBe('Verdicts compare each segment with all ExoClick tests traffic: 9.4% accounts per new visitor.')
        ->and($none['foot'][2])->toBe('Cost per registration needs spend, and ExoClick tests spent nothing in these days.');

    Livewire::withQueryParams(['tab' => 'campaigns', 'source' => 'paid:'.$this->exo->id])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('No segments yet. Add one to see a site, zone, campaign, creative, device or country of ExoClick tests on its own.');
});

it('opens the segments from a paid source, and a segment’s drawer from the address — a new one, or one of the source’s', function () {
    segmentsCardFixture($this->campaign);
    $mine = segmentOf($this->exo, 'Publisher 1182', [['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']]]);
    $foreign = segmentOf(TrafficSource::factory()->create(), 'Elsewhere', [['parameter' => 'site', 'operator' => 'in', 'values' => ['1']]]);
    $tag = TrafficCampaign::factory()->create();

    $page = Livewire::withQueryParams(['tab' => 'campaigns'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSeeHtml('openSegments(\'paid:'.$this->exo->id.'\')')
        ->set('campaign', 'paid:'.$tag->id)
        ->call('openSegments', 'paid:'.$this->exo->id)
        // A campaign not of the source is dropped, as choosing the source drops it.
        ->assertSet('source', 'paid:'.$this->exo->id)->assertSet('campaign', '')
        ->call('addSegment')->assertSet('segment', 'new');

    expect($page->viewData('data')['segments']['editing'])->toBe('new');
    $page->assertSeeHtml('data-testid="analytics-segment-drawer"')->assertSee('New segment');

    $page->call('editSegment', $mine->id)->assertSet('segment', (string) $mine->id)->assertSee('Edit segment');
    expect($page->viewData('data')['segments']['editing'])->toBe($mine->id);

    $page->call('closeSegment')->assertSet('segment', '');

    // A segment of another source opens nothing; anything else isn't read at all.
    expect(analyticsSegments(['source' => 'paid:'.$this->exo->id, 'segment' => $foreign->id])['editing'])->toBeNull();
    Livewire::withQueryParams(['tab' => 'campaigns', 'source' => 'paid:'.$this->exo->id, 'segment' => 'edit'])->test(GrowthAnalyticsPage::class)->assertSet('segment', '');
    Livewire::withQueryParams(['tab' => 'campaigns', 'source' => 'reddit:organic', 'segment' => 'new'])->test(GrowthAnalyticsPage::class)->assertSet('segment', '');
    Livewire::withQueryParams(['tab' => 'overview', 'source' => 'paid:'.$this->exo->id, 'segment' => 'new'])->test(GrowthAnalyticsPage::class)->assertSet('segment', '');

    // Another source, or none, closes it; Segments of a source not paid changes nothing.
    Livewire::withQueryParams(['tab' => 'campaigns', 'source' => 'paid:'.$this->exo->id, 'segment' => $mine->id])->test(GrowthAnalyticsPage::class)
        ->assertSet('segment', (string) $mine->id)
        ->set('source', 'direct')->assertSet('segment', '')
        ->call('openSegments', 'direct')->assertSet('source', 'direct');
});

it('drops the creative and the tracking link with the campaign a source drops, and keeps all three with a source the campaign came with', function () {
    $pop = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $link = TrackingLink::factory()->create(['utm_campaign' => 'versus_launch', 'utm_content' => 'post_01']);
    analyticsVisit('reader', ['source_key' => 'reddit:organic', 'campaign_key' => 'tag:versus_launch', 'creative_key' => 'post_01']);
    analyticsVisit('clicker', ['source_key' => 'paid:'.$this->exo->id, 'campaign_key' => 'tag:versus_launch', 'creative_key' => 'post_01']);

    // Campaigns opened from the link: its campaign, its creative and the link itself in the address.
    $fromLink = function (string $method, string $source) use ($link): array {
        $page = Livewire::withQueryParams(['tab' => 'campaigns', 'campaign' => 'tag:versus_launch', 'creative' => 'post_01', 'link' => $link->id])
            ->test(GrowthAnalyticsPage::class)->call('load')->call($method, $source);

        return [$page->get('campaign'), $page->get('creative'), $page->get('link')];
    };
    $all = ['tag:versus_launch', 'post_01', (string) $link->id];

    expect($fromLink('openSegments', 'paid:'.$this->exo->id))->toBe($all)
        ->and($fromLink('pickSource', 'reddit:organic'))->toBe($all)
        ->and($fromLink('openSegments', 'paid:'.$pop->id))->toBe(['', '', ''])
        ->and($fromLink('pickSource', 'google:organic'))->toBe(['', '', '']);
});

it('makes a segment in the drawer, previewing what its conditions catch as they are typed', function () {
    segmentsCardFixture($this->campaign);

    $editor = segmentEditor($this->exo)
        ->assertSee('New segment')
        ->assertSee('Fill every condition to see how many visitors it matches.')
        ->assertSet('conditions', [['parameter' => 'site', 'operator' => 'in', 'values' => '']])
        ->set('conditions.0.values', '1182, 3300')
        ->assertSee('Matches 30 visitors and 5 registrations of ExoClick tests in 20 Oct — 18 Nov 2026, including visits before the segment existed.')
        ->call('addCondition')
        ->assertSet('conditions.1', ['parameter' => 'device', 'operator' => 'in', 'values' => 'mobile'])
        ->call('toggleDevice', 1, 'desktop')->assertSet('conditions.1.values', 'mobile, desktop')
        ->call('toggleDevice', 1, 'mobile')->assertSet('conditions.1.values', 'desktop')
        ->assertSee('Matches 0 visitors and 0 registrations')
        ->set('conditions.1.operator', 'out')
        ->assertSee('Matches 30 visitors')
        ->call('pickParameter', 1, 'country')->assertSet('conditions.1', ['parameter' => 'country', 'operator' => 'out', 'values' => ''])
        ->set('conditions.1.values', 'XX')
        ->assertSee('No country has the code XX.')
        ->assertSee('Fix the conditions marked to see how many visitors it matches.')
        ->call('removeCondition', 1)
        // Nothing is saved without a name.
        ->call('save')->assertHasErrors(['name'])
        ->set('name', '  Publisher 1182  ')
        ->call('save')
        ->assertDispatched('traffic-segment-saved', message: 'Segment “Publisher 1182” saved');

    $segment = TrafficSegment::query()->sole();

    expect($segment->only(['source_id', 'name', 'rules', 'created_by_user_id', 'updated_by_user_id', 'lock_version']))->toBe([
        'source_id' => $this->exo->id,
        'name' => 'Publisher 1182',
        'rules' => [['parameter' => 'site', 'operator' => 'in', 'values' => ['1182', '3300']]],
        'created_by_user_id' => $this->admin->id,
        'updated_by_user_id' => null,
        'lock_version' => 1,
    ]);

    // The page closes the drawer and says so.
    Livewire::withQueryParams(['tab' => 'campaigns', 'source' => 'paid:'.$this->exo->id, 'segment' => 'new'])->test(GrowthAnalyticsPage::class)
        ->call('segmentSaved', 'Segment “Publisher 1182” saved')
        ->assertSet('segment', '')
        ->assertDispatched('rg-admin-toast', message: 'Segment “Publisher 1182” saved', tone: 'success');
    $editor->assertHasNoErrors();
});

it('takes a campaign by its id in the network, only one of the segment’s own source', function () {
    $foreign = TrafficCampaign::factory()->create(['external_id' => '777']);

    segmentEditor($this->exo)
        ->set('name', 'Campaigns')
        ->call('pickParameter', 0, 'campaign')
        ->set('conditions.0.values', '777')
        ->assertSee('ExoClick tests has no campaign 777.')
        ->call('save')
        ->assertHasErrors(['conditions.0.values'])
        ->assertNotDispatched('traffic-segment-saved');

    expect(TrafficSegment::query()->exists())->toBeFalse()
        ->and(fn () => app(SaveTrafficSegmentAction::class)->handle($this->admin, $this->exo->id, null, ['name' => 'Elsewhere', 'conditions' => [['parameter' => 'campaign', 'operator' => 'in', 'values' => (string) $foreign->external_id]]]))
        ->toThrow(ValidationException::class, 'ExoClick tests has no campaign 777.');

    $segment = app(SaveTrafficSegmentAction::class)->handle($this->admin, $this->exo->id, null, ['name' => 'Popunder', 'conditions' => [['parameter' => 'campaign', 'operator' => 'out', 'values' => '881204']]]);

    expect($segment->rules)->toBe([['parameter' => 'campaign', 'operator' => 'out', 'values' => [$this->campaign->id]]]);

    // Drawn again by the network's id, and never moved to another source.
    segmentEditor($this->exo, $segment)->assertSet('conditions.0.values', '881204')->assertSee('Edit segment');
    expect(fn () => app(SaveTrafficSegmentAction::class)->handle($this->admin, $foreign->source_id, $segment->id, ['name' => 'Moved', 'conditions' => [['parameter' => 'site', 'operator' => 'in', 'values' => '1']]], 1))
        ->toThrow(CannotManageTrafficException::class, 'This segment no longer exists.');
});

it('saves only at the version the drawer was drawn from, and Reload draws the segment as it is now', function () {
    $segment = segmentOf($this->exo, 'Publisher 1182', [['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']]]);
    $editor = segmentEditor($this->exo, $segment)->assertSet('name', 'Publisher 1182')->assertSet('lockVersion', 1);

    // Somebody else saves it meanwhile.
    app(SaveTrafficSegmentAction::class)->handle(User::factory()->admin()->create(), $this->exo->id, $segment->id, ['name' => 'Their name', 'conditions' => [['parameter' => 'zone', 'operator' => 'in', 'values' => '44718']]], 1);

    $editor->set('name', 'My name')
        ->call('save')
        ->assertSet('stale', true)
        ->assertDispatched('rg-admin-toast', message: 'Someone saved this segment after you opened it. Reload to see their changes.', tone: 'error')
        ->assertNotDispatched('traffic-segment-saved')
        ->assertSee('Someone saved this segment after you opened it.')
        // A stale drawer deletes nothing either.
        ->call('delete')->assertNotDispatched('traffic-segment-deleted');

    expect($segment->fresh()->name)->toBe('Their name');

    $editor->call('reload')
        ->assertSet('stale', false)
        ->assertSet('name', 'Their name')
        ->assertSet('conditions', [['parameter' => 'zone', 'operator' => 'in', 'values' => '44718']])
        ->set('name', 'My name')
        ->call('save')
        ->assertDispatched('traffic-segment-saved');

    expect($segment->fresh()->only(['name', 'lock_version']))->toBe(['name' => 'My name', 'lock_version' => 3]);
});

it('deletes a segment only by marking it, and Undo brings it back as it was', function () {
    segmentsCardFixture($this->campaign);
    $segment = segmentOf($this->exo, 'Publisher 1182', [['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']]]);

    segmentEditor($this->exo, $segment)
        ->call('delete')
        ->assertDispatched('traffic-segment-deleted', id: $segment->id, name: 'Publisher 1182');

    expect(TrafficSegment::query()->exists())->toBeFalse()
        ->and($segment->fresh()->trashed())->toBeTrue()
        ->and($segment->fresh()->rules)->toBe([['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']]]);

    $page = Livewire::withQueryParams(['tab' => 'campaigns', 'source' => 'paid:'.$this->exo->id, 'segment' => $segment->id])->test(GrowthAnalyticsPage::class)->call('load')
        ->call('segmentDeleted', $segment->id, 'Publisher 1182')
        ->assertSet('segment', '')
        ->assertDispatched('rg-admin-toast', message: 'Segment “Publisher 1182” deleted', tone: 'success', action: ['label' => 'Undo', 'event' => 'restore-traffic-segment', 'params' => ['id' => $segment->id]]);

    expect(array_column($page->viewData('data')['segments']['rows'], 'kind'))->toBe(['all']);

    $page->call('restoreSegment', $segment->id)
        ->assertDispatched('rg-admin-toast', message: 'Segment “Publisher 1182” is back', tone: 'success');

    expect(array_column($page->viewData('data')['segments']['rows'], 'name'))->toBe(['All ExoClick tests', 'Publisher 1182', 'Not in any segment'])
        ->and($segment->fresh()->trashed())->toBeFalse()
        ->and($segment->fresh()->updated_by_user_id)->toBe($this->admin->id);

    // Bringing back one that isn't deleted, or deleting one already deleted, changes nothing.
    $version = $segment->fresh()->lock_version;
    app(RestoreTrafficSegmentAction::class)->handle($this->admin, $segment);
    expect($segment->fresh()->lock_version)->toBe($version);

    app(DeleteTrafficSegmentAction::class)->handle($this->admin, $segment, $version);
    app(DeleteTrafficSegmentAction::class)->handle($this->admin, $segment, $version);
    expect($segment->fresh()->lock_version)->toBe($version + 1);

    // A drawer drawn before a save of somebody else's doesn't delete it.
    $other = segmentOf($this->exo, 'Desktop', [['parameter' => 'device', 'operator' => 'in', 'values' => ['desktop']]]);
    expect(fn () => app(DeleteTrafficSegmentAction::class)->handle($this->admin, $other, 7))->toThrow(CannotManageTrafficException::class);
});

it('holds at most so many segments a source, Add segment then off, and Undo past them refused', function () {
    segmentsCardFixture($this->campaign);
    TrafficSegment::factory()->count(SaveTrafficSegmentAction::MOST_SEGMENTS - 1)->create(['source_id' => $this->exo->id]);
    $deleted = TrafficSegment::factory()->create(['source_id' => $this->exo->id, 'deleted_at' => now()]);
    $save = fn () => app(SaveTrafficSegmentAction::class)->handle($this->admin, $this->exo->id, null, ['name' => 'One more', 'conditions' => [['parameter' => 'site', 'operator' => 'in', 'values' => '1']]]);

    expect(analyticsSegments(['source' => 'paid:'.$this->exo->id])['full'])->toBeFalse();

    $save();

    expect(analyticsSegments(['source' => 'paid:'.$this->exo->id])['full'])->toBeTrue()
        ->and($save)->toThrow(CannotManageTrafficException::class, 'ExoClick tests already has 20 segments, the most a source holds. Delete one first.');

    Livewire::withQueryParams(['tab' => 'campaigns', 'source' => 'paid:'.$this->exo->id])->test(GrowthAnalyticsPage::class)->call('load')
        ->call('restoreSegment', $deleted->id)
        ->assertDispatched('rg-admin-toast', message: 'Its source already has 20 segments, the most a source holds, so it stays deleted.', tone: 'error');

    expect($deleted->fresh()->trashed())->toBeTrue();
});

it('reads a bounded number of queries however many segments a source has', function () {
    segmentsCardFixture($this->campaign);
    Game::factory()->status(GameStatus::Live)->create();

    $queries = function (): int {
        $page = Livewire::withQueryParams(['tab' => 'campaigns', 'source' => 'paid:'.$this->exo->id])->test(GrowthAnalyticsPage::class)->call('load');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('$refresh');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    segmentOf($this->exo, 'One', [['parameter' => 'campaign', 'operator' => 'in', 'values' => [$this->campaign->id]]]);
    $one = $queries();

    foreach (range(1, 9) as $n) {
        segmentOf($this->exo, 'More '.$n, [['parameter' => 'site', 'operator' => 'in', 'values' => [(string) $n]], ['parameter' => 'campaign', 'operator' => 'out', 'values' => [$this->campaign->id]]]);
    }

    expect($queries())->toBe($one)
        ->and($one)->toBeLessThanOrEqual(30);
});

it('answers only an administrator who may manage traffic sources, every request asking again', function () {
    segmentsCardFixture($this->campaign);
    $segment = segmentOf($this->exo, 'Publisher 1182', [['parameter' => 'site', 'operator' => 'in', 'values' => ['1182']]]);
    $segment->delete();
    $page = fn () => Livewire::withQueryParams(['tab' => 'campaigns', 'source' => 'paid:'.$this->exo->id])->test(GrowthAnalyticsPage::class)->call('load');
    [$adding, $undoing] = [$page(), $page()];
    $editor = segmentEditor($this->exo)->set('name', 'Mine')->set('conditions.0.values', '1182');

    // The same session, demoted since the page was drawn.
    $this->admin->forceFill(['role' => UserRole::Moderator])->save();

    $adding->call('addSegment')->assertForbidden();
    $undoing->call('restoreSegment', $segment->id)->assertForbidden();
    $editor->call('save')->assertForbidden();
    segmentEditor($this->exo)->assertForbidden();

    expect(TrafficSegment::query()->exists())->toBeFalse()
        ->and(fn () => app(SaveTrafficSegmentAction::class)->handle($this->admin->fresh(), $this->exo->id, null, ['name' => 'Mine', 'conditions' => [['parameter' => 'site', 'operator' => 'in', 'values' => '1']]]))
        ->toThrow(CannotManageTrafficException::class, 'You can’t manage traffic sources any more, so nothing was saved.');

    $this->actingAs(User::factory()->moderator()->create());
    $this->get(GrowthAnalyticsPage::getUrl(['tab' => 'campaigns', 'source' => 'paid:'.$this->exo->id, 'segment' => 'new']))->assertForbidden();
});

it('says what a segment’s Activated counts', function () {
    segmentsCardFixture($this->campaign);

    $segments = analyticsSegments(['source' => 'paid:'.$this->exo->id]);

    expect($segments['rows'][0]['cells']['activated'])->toMatchArray(['value' => '1', 'title' => '1 of 5 accounts came back and acted'])
        ->and($segments['titles']['Activated'])->toBe('Accounts that came back and played, voted, commented or posted on one of the 7 days after their sign-up day')
        ->and($segments['titles']['Registrations'])->toBe('Confirmed accounts: the email link clicked, or a Google or Facebook sign-up, which confirms at once');
});
