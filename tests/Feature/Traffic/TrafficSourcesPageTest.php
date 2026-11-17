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

    Livewire::withQueryParams(['tab' => $tab, 'by' => $text === 'Sites' ? 'site' : 'source'])->test(TrafficSourcesPage::class)
        ->call('load')
        ->assertOk()
        ->assertSee($text);
})->with([
    'report' => ['report', 'PopAds main'],
    'report by site' => ['report', 'Sites'],
    'campaigns' => ['campaigns', 'US popunder'],
    'value' => ['value', 'What an account is worth'],
    'spend' => ['spend', 'Spend without visits'],
    'sources' => ['sources', 'Tracking'],
    'logs' => ['logs', 'failed'],
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
