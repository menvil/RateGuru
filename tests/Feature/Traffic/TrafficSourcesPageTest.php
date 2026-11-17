<?php

use App\Enums\TrafficCampaignStage;
use App\Enums\TrafficCampaignStatus;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\TrafficCampaign;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('opens for an active administrator only, reading no visits until the page is in a browser', function () {
    $this->get(TrafficSourcesPage::getUrl())->assertOk()->assertSee('Traffic sources')->assertSee('Add source');

    $this->actingAs(User::factory()->moderator()->create())->get(TrafficSourcesPage::getUrl())->assertForbidden();
});

it('draws every tab on real figures', function (string $tab, string $text) {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $source->id, 'name' => 'US popunder']);
    $visit = TrafficVisit::factory()->create(['source_id' => $source->id, 'campaign_id' => $campaign->id, 'site_ref' => '40123', 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => now(), 'spend' => '0.25', 'account_at' => now()]);
    TrafficPostback::factory()->create(['conversion_id' => TrafficConversion::factory()->create(['visit_id' => $visit->id])->id, 'status' => 'failed']);

    Livewire::withQueryParams(['tab' => $tab, 'view' => $text === 'site 40123' ? 'sites' : 'sources'])->test(TrafficSourcesPage::class)
        ->call('load')
        ->assertOk()
        ->assertSee($text);
})->with([
    'report' => ['report', 'PopAds main'],
    'report, sites and zones' => ['report', 'site 40123'],
    'campaigns' => ['campaigns', 'US popunder'],
    'value' => ['value', 'What a new user is worth'],
    'spend' => ['spend', 'Where spend comes from'],
    'sources' => ['sources', 'How a visit gets its source'],
    'logs' => ['logs', 'Failed only'],
]);

it('creates a source from a preset in its editor', function () {
    Livewire::test(TrafficSourcesPage::class)
        ->call('startAdding')
        ->assertSee('PopAds')
        ->call('openEditor', 'new:popads')
        ->assertSet('draft.source.link_key', 'pop')
        ->set('draft.source.name', 'PopAds main')
        ->call('saveSource')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Source saved');

    expect(TrafficSource::query()->sole())->name->toBe('PopAds main')->preset_key->toBe('popads');
});

it('shows a source editor’s refusals by its fields', function () {
    TrafficSource::factory()->create(['link_key' => 'pop']);

    Livewire::test(TrafficSourcesPage::class)
        ->call('openEditor', 'new:popads')
        ->set('draft.source.link_key', 'pop')
        ->set('draft.source.name', 'Another')
        ->call('saveSource')
        ->assertHasErrors(['link_key']);
});

it('switches recording on, and saves new prices as a new set', function () {
    $before = TrafficPriceSet::current()->id;

    Livewire::test(TrafficSourcesPage::class)
        ->set('settings.capture_enabled', true)
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->set('prices.account_price', '0.75')
        ->call('savePrices')
        ->assertHasNoErrors();

    expect(TrafficSetting::current()->capture_enabled)->toBeTrue()
        ->and(TrafficPriceSet::current()->id)->not->toBe($before)
        ->and(TrafficPriceSet::current()->account_price)->toBe('0.7500');
});

it('sets a campaign’s stage from its panel', function () {
    $campaign = TrafficCampaign::factory()->create();

    Livewire::withQueryParams(['camp' => $campaign->id])->test(TrafficSourcesPage::class)
        ->assertSee('Stage')
        ->call('setStage', 'test');

    expect($campaign->fresh())->stage->toBe(TrafficCampaignStage::Test)->status->toBe(TrafficCampaignStatus::Active);
});

it('never lets a password manager fill the network key field', function () {
    $source = TrafficSource::factory()->create(['connector' => 'popads']);

    Livewire::withQueryParams(['tab' => 'sources', 'edit' => $source->id])->test(TrafficSourcesPage::class)
        ->assertSee('id="rg-admin-source-secret"', false)
        ->assertSee('autocomplete="new-password"', false)
        ->assertSet('edit', (string) $source->id);
});

it('gives each country group it adds a key no other group has', function () {
    $page = Livewire::test(TrafficSourcesPage::class)->call('addGroup')->call('addGroup');
    $page->call('removeGroup', 1)->call('addGroup');

    $keys = array_column($page->get('groups'), 'key');

    expect($keys)->toBe(array_values(array_unique($keys)));
});

it('edits a source in panes, with what its preset filled in, and names a parameter added without a name for what it carries', function () {
    $source = TrafficSource::factory()->create(['name' => 'ExoClick tests', 'preset_key' => 'exoclick', 'link_key' => 'exo']);

    $page = Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $source->id])->test(TrafficSourcesPage::class)
        ->assertSee('Link & parameters')
        ->assertSee('Started from the ExoClick preset')
        ->assertSee('Attribution window')
        ->assertSee('Detected by RateGuru')
        ->call('addParam')
        ->set('draft.source.link_params.'.count($source->link_params).'.kind', 'creative')
        ->set('draft.source.link_params.'.count($source->link_params).'.param', 'cr')
        ->set('draft.source.link_params.'.count($source->link_params).'.token', '{creative}')
        ->call('saveSource')
        ->assertHasNoErrors();

    expect(collect($source->fresh()->link_params)->firstWhere('param', 'cr')['label'])->toBe('Creative');
});

it('shows a campaign in its panel — stages, results, targeting typed in where no network reports it — and opens it in the report', function () {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $source->id, 'name' => 'US popunder', 'countries' => ['US']]);

    $page = Livewire::withQueryParams(['tab' => 'campaigns', 'camp' => (string) $campaign->id])->test(TrafficSourcesPage::class)
        ->assertSee('Results by stage')
        ->assertSee('Typed in here')
        ->assertSee('United States')
        ->call('toggleCampaignCountry', 'ca')
        ->call('toggleCampaignDevice', 'mobile')
        ->call('saveCampaign')
        ->assertHasNoErrors();

    expect($campaign->fresh())->countries->toBe(['US', 'CA'])->devices->toBe(['mobile']);

    $page->call('campaignReport')->assertSet('tab', 'report')->assertSet('campaignId', (string) $campaign->id)->assertSet('camp', '');
});
