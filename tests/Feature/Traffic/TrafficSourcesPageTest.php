<?php

use App\Enums\TrafficCampaignStage;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficEvent;
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
        ->set('prices.login_price', '0.15')
        ->call('savePrices')
        ->assertHasNoErrors()
        ->assertSet('prices.login_price', '0.15');

    expect(TrafficSetting::current()->capture_enabled)->toBeTrue()
        ->and(TrafficPriceSet::current()->id)->not->toBe($before)
        ->and(TrafficPriceSet::current()->account_price)->toBe('0.7500')
        ->and(TrafficPriceSet::current()->login_price)->toBe('0.1500');
});

it('gives a paid source its own account price on Value, beside what its accounts brought, saved with the prices', function () {
    $exo = TrafficSource::factory()->create(['name' => 'ExoClick main']);
    $idle = TrafficSource::factory()->create(['name' => 'PopAds idle']);
    TrafficVisit::factory()->create(['source_id' => $exo->id, 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'opened_at' => '2026-11-16 10:00:00', 'account_at' => '2026-11-16 11:00:00', 'value_real' => '0.9']);
    $before = TrafficPriceSet::current()->id;

    $page = Livewire::withQueryParams(['tab' => 'value'])->test(TrafficSourcesPage::class)
        ->call('load')
        ->assertSee('Account price')
        ->assertSeeHtml('aria-label="Account price of ExoClick main"')
        ->assertSeeHtml('placeholder="0.50"')
        // Every paid source has a row, one without a visit in the period too; an account from ExoClick brought $0.90 on average.
        ->assertViewHas('sourceRows', fn (array $rows): bool => collect($rows)->firstWhere('id', $exo->id)['brings'] === '0.90000000'
            && collect($rows)->firstWhere('id', $idle->id)['figures']->accounts === 0)
        ->set('accountPrices.'.$exo->id, '0.85')
        ->call('savePrices')
        ->assertHasNoErrors()
        ->assertSet('accountPrices.'.$exo->id, '0.85')
        ->assertDispatched('rg-admin-toast', message: 'Prices saved. Visits from now on count by them; earlier ones keep theirs.');

    $set = TrafficPriceSet::current();

    expect($set->id)->not->toBe($before)
        ->and($set->sourceAccountPrices())->toBe([$exo->id => '0.8500'])
        ->and($set->summary)->toEndWith('; ExoClick main account $0.85');

    $page->assertViewHas('priceChanges', fn (array $changes): bool => str_ends_with($changes[0]['summary'], 'ExoClick main account $0.85'))
        ->assertViewHas('ownPrices', [['name' => 'ExoClick main', 'price' => '0.8500']])
        ->assertSee('own account price: ExoClick main $0.85')
        ->set('accountPrices.'.$idle->id, '-2')
        ->call('savePrices')
        ->assertHasErrors(['source_account_prices.'.$idle->id])
        // Said aloud, since the price can be far from the button that saved it.
        ->assertDispatched('rg-admin-toast', tone: 'error')
        ->set('accountPrices.'.$idle->id, '')
        ->set('accountPrices.'.$exo->id, '')
        ->call('savePrices')
        ->assertHasNoErrors();

    expect(TrafficPriceSet::current()->source_account_prices)->toBeNull();

    // With the page on one source, the card lists that source alone, not every other paid source as empty.
    Livewire::withQueryParams(['tab' => 'value', 'source' => (string) $exo->id])->test(TrafficSourcesPage::class)
        ->call('load')
        ->assertViewHas('sourceRows', fn (array $rows): bool => collect($rows)->whereNotNull('id')->pluck('id')->all() === [$exo->id]);
});

it('values a new visitor on Value with the sources’ own account prices mixed in as their accounts came', function () {
    $exo = TrafficSource::factory()->create(['name' => 'ExoClick main']);
    TrafficPriceSet::factory()->create(['source_account_prices' => [$exo->id => '0.8000']]);
    // 10 of the site's 40 accounts came from ExoClick: (10 × $0.80 + 30 × $0.50) / 40 an account.
    finishedChanceRun([
        '*' => ['opened' => ['chance' => 0.1, 'visitors' => 400, 'accounts' => 40]],
        's'.$exo->id => ['opened' => ['chance' => 0.1, 'visitors' => 100, 'accounts' => 10]],
    ]);

    Livewire::withQueryParams(['tab' => 'value'])->test(TrafficSourcesPage::class)
        ->call('load')
        ->assertViewHas('visitorWorth', '0.05750000')
        ->assertViewHas('sourceRows', fn (array $rows): bool => collect($rows)->firstWhere('id', $exo->id)['brings'] === '0.80000000')
        ->assertViewHas('unpaidRow', fn (array $row): bool => $row['brings'] === '0.50000000')
        ->assertSee('the sources’ own account prices mixed in as their accounts came');
});

it('shows a source’s account price in its editor, its own or the site’s, with the way to Value', function () {
    $exo = TrafficSource::factory()->create(['name' => 'ExoClick main']);
    $pop = TrafficSource::factory()->create(['name' => 'PopAds main']);
    TrafficPriceSet::factory()->create(['source_account_prices' => [$exo->id => '0.8500']]);
    // The same period, at the card where it is set, in a tab of its own.
    $value = e(TrafficSourcesPage::getUrl(['tab' => 'value']).'#traffic-value-sources');

    Livewire::withQueryParams(['tab' => 'sources', 'edit' => $exo->id])->test(TrafficSourcesPage::class)
        ->assertSeeHtmlInOrder(['data-testid="traffic-source-account-price"', 'What an account from it is worth', '$0.85', 'href="'.$value.'"', 'Set on Value', 'Its own account price, in place of the site’s $0.50.']);

    Livewire::withQueryParams(['tab' => 'sources', 'edit' => $pop->id])->test(TrafficSourcesPage::class)
        ->assertSeeHtmlInOrder(['data-testid="traffic-source-account-price"', '$0.50', 'The site’s account price: it has none of its own.']);
});

it('refuses a sign-in price that is no amount, by its field', function () {
    Livewire::test(TrafficSourcesPage::class)
        ->set('prices.login_price', 'free')
        ->call('savePrices')
        ->assertHasErrors(['login_price']);
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

it('warns when two postbacks of a source go to the same goal of the network', function () {
    $page = Livewire::test(TrafficSourcesPage::class)
        ->call('openEditor', 'new:popads')
        ->assertDontSee('go to the same goal of the network')
        ->call('addRule')
        ->set('draft.rules.0.event', 'completed')
        ->set('draft.rules.0.url_template', 'https://serve.popads.net/cpixel.php?s2s=1&aid=7&id={click_id}&value={value}')
        ->set('draft.rules.0.enabled', true)
        ->call('addRule')
        ->set('draft.rules.1.event', 'account')
        ->set('draft.rules.1.url_template', 'https://serve.popads.net/cpixel.php?s2s=1&aid=7&id={click_id}&value={value}')
        ->set('draft.rules.1.enabled', true)
        ->assertSee('Game finished and New account confirmed go to the same goal of the network');

    // The same event twice is named once.
    $page->set('draft.rules.0.event', 'account')
        ->assertSee('2 postbacks on “New account confirmed” go to the same goal of the network', false);

    // One of them switched off: nothing is counted twice.
    $page->set('draft.rules.1.enabled', false)
        ->assertDontSee('go to the same goal of the network');
});

it('asks a rule turned to a settled visit for one that started the game, and keeps that floor only on such a rule', function () {
    $page = Livewire::test(TrafficSourcesPage::class)
        ->call('openEditor', 'new:popads')
        ->set('draft.source.name', 'PopAds main')
        ->call('addRule')
        ->assertDontSee('Only a visit that got at least to')
        ->set('draft.rules.0.event', 'settled')
        ->assertSet('draft.rules.0.needs_event', 'started')
        ->assertSee('Only a visit that got at least to')
        ->set('draft.rules.0.needs_event', 'completed')
        ->set('draft.rules.0.url_template', 'https://serve.popads.net/cpixel.php?s2s=1&aid=7&id={click_id}&value={value}')
        ->set('draft.rules.0.enabled', true)
        ->call('addRule')
        ->set('draft.rules.1.event', 'account')
        ->set('draft.rules.1.needs_event', 'completed')
        ->set('draft.rules.1.url_template', 'https://serve.popads.net/cpixel.php?s2s=1&aid=8&id={click_id}')
        ->call('saveSource')
        ->assertHasNoErrors();

    $rules = TrafficSource::query()->sole()->postbackRules()->orderBy('position')->get();

    expect($rules[0]->event)->toBe(TrafficEvent::Settled)->and($rules[0]->needs_event)->toBe(TrafficEvent::Completed)
        ->and($rules[1]->event)->toBe(TrafficEvent::Account)->and($rules[1]->needs_event)->toBeNull();

    // A floor that isn't one is refused.
    $page->call('openEditor', (string) TrafficSource::query()->sole()->id)
        ->set('draft.rules.0.needs_event', 'shared')
        ->call('saveSource')
        ->assertHasErrors(['rules.0.needs_event']);
});
