<?php

use App\Actions\Traffic\SaveTrafficSettingsAction;
use App\Enums\TrafficCampaignStage;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficEvent;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\Game;
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

it('hands the link to Copy link as written, with no Blade directive left in the page for Alpine to choke on', function () {
    TrafficSource::factory()->create(['name' => 'ExoClick tests', 'link_key' => 'exo']);
    Game::factory()->create(['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']);

    $page = Livewire::withQueryParams(['tab' => 'campaigns'])->test(TrafficSourcesPage::class)->call('load');
    $link = (string) $page->viewData('link');

    expect($link)->toContain('/games/trust-your-taste?rg_src=exo')
        ->and($page->html())->toContain('data-copy="'.e($link).'"')
        ->and($page->html())->not->toContain('@js(');
});

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

it('switches site visits on right under Record visits, keeps them off and out of reach while that is off, and refuses a stale save', function () {
    $switch = fn ($page): DOMElement => livewireDom($page)->query('//button[@id="rg-admin-traffic-site-capture"]')->item(0);
    $page = Livewire::withQueryParams(['tab' => 'sources'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSeeInOrder(['Record visits', 'Record site visits', 'Feed, posts, profiles and pages, one visit per browser every 30 minutes. Needs Record visits.', 'Send postbacks']);

    expect($switch($page)->hasAttribute('disabled'))->toBeTrue()
        ->and($switch($page)->getAttribute('aria-checked'))->toBe('false');

    $page->set('settings.capture_enabled', true);
    expect($switch($page)->hasAttribute('disabled'))->toBeFalse();

    $page->set('settings.site_capture_enabled', true)->call('saveSettings')->assertHasNoErrors()->assertDispatched('rg-admin-toast', message: 'Tracking saved');

    expect(TrafficSetting::current())->capture_enabled->toBeTrue()->site_capture_enabled->toBeTrue()
        ->and(TrafficSetting::captureFlags())->toBe(['capture' => true, 'site' => true])
        ->and($switch($page)->getAttribute('aria-checked'))->toBe('true');

    // Record visits off takes site visits with it, on the card at once and once saved.
    $page->set('settings.capture_enabled', false)->assertSet('settings.site_capture_enabled', false);
    expect($switch($page)->hasAttribute('disabled'))->toBeTrue();
    $page->call('saveSettings')->assertHasNoErrors();

    expect(TrafficSetting::current())->capture_enabled->toBeFalse()->site_capture_enabled->toBeFalse()
        ->and(TrafficSetting::captureFlags())->toBe(['capture' => false, 'site' => false]);

    // Saved by someone else meanwhile: nothing changes, and the card says to reload.
    app(SaveTrafficSettingsAction::class)->handle(User::factory()->admin()->create(), ['postbacks_enabled' => true], TrafficSetting::current()->lock_version);
    $page->set('settings.capture_enabled', true)->set('settings.site_capture_enabled', true)->call('saveSettings')
        ->assertDispatched('rg-admin-toast', message: 'Someone saved the tracking settings after you opened it. Reload to see their changes.', tone: 'error');

    expect(TrafficSetting::current())->capture_enabled->toBeFalse()->site_capture_enabled->toBeFalse();
});

it('never keeps site visits on with Record visits off, whatever it is sent', function () {
    $settings = app(SaveTrafficSettingsAction::class)->handle($this->admin, ['capture_enabled' => false, 'site_capture_enabled' => true], 1);

    expect($settings->fresh()->site_capture_enabled)->toBeFalse()
        ->and(TrafficSetting::captureFlags())->toBe(['capture' => false, 'site' => false]);
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

it('chooses every day in a drop-down calendar, never the browser’s own: the period’s range, a fixed price’s days, a campaign’s price and the day corrected', function () {
    $network = TrafficSource::factory()->create(['name' => 'PopAds main', 'reporting_timezone' => 'Asia/Tokyo']);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $network->id, 'name' => 'US popunder']);

    // The period's range: one calendar in the picker's own panel, of the UTC days a report reads within the days visits are kept, taken the moment its end is picked.
    $html = Livewire::withQueryParams(['from' => '2026-11-01', 'to' => '2026-11-05'])->test(TrafficSourcesPage::class)->html();
    expect(substr_count($html, 'rg-admin-date-range--inline'))->toBe(1)
        ->and($html)->toMatch('/<div class="rg-admin-period__range" x-on:rg-admin-range-chosen="takeRange\(\$event\.detail\)">/')
        ->and($html)->toMatch('/rg-admin-date-range--inline"\s+data-now="2026-11-17T12:00:00"\s+role="group" aria-label="Custom range"\s+x-data="\{\s+from: \'2026-11-01\',\s+to: \'2026-11-05\',\s+min: \'2025-10-14\',\s+max: \'2026-11-17\',\s+most: null,/')
        ->and($html)->toContain('this.$wire.applyRange(range.from, range.to)')
        ->and($html)->not->toContain('id="rg-admin-traffic-period-from"')
        ->and($html)->not->toContain('rg-admin-date-time--date')
        ->and($html)->not->toContain('type="date"');

    // A fixed price's first and last day in one calendar, each bound as wire:model binds one; the last may stay open.
    $html = Livewire::withQueryParams(['tab' => 'sources'])->test(TrafficSourcesPage::class)
        ->call('openEditor', 'new:placement')
        ->set('draft.source.spend_mode', 'fixed')
        ->set('draft.source.fixed_from', '2026-11-20')
        ->html();
    expect($html)->toContain('id="rg-admin-source-dates"')
        ->toMatch('/x-data="\{\s+from: \$wire\.\$entangle\(\'draft\.source\.fixed_from\'\),\s+to: \$wire\.\$entangle\(\'draft\.source\.fixed_to\'\),/')
        ->toContain('openEnd: true')
        ->toMatch('/x-text="shown"\s*>From 20 Nov 2026 · no end<\/span>/')
        ->toContain('x-on:click="noEnd()"')
        ->not->toContain('id="rg-admin-source-from"')
        ->not->toContain('id="rg-admin-source-to"')
        ->not->toContain('type="date"');

    // A refusal for either day is the field's.
    Livewire::withQueryParams(['tab' => 'sources'])->test(TrafficSourcesPage::class)
        ->call('openEditor', 'new:placement')
        ->set('draft.source.name', 'Cat blog')
        ->set('draft.source.link_key', 'catblog')
        ->set('draft.source.spend_mode', 'fixed')
        ->set('draft.source.fixed_amount', '70')
        ->set('draft.source.fixed_from', '2026-11-20')
        ->set('draft.source.fixed_to', '2026-11-10')
        ->call('saveSource')
        ->assertHasErrors(['fixed_to'])
        ->assertSeeHtml('class="rg-admin-date-time__trigger rg-admin-date-time__trigger--invalid"')
        ->assertSeeHtml('id="rg-admin-source-dates-error"');

    // A campaign's price, from the day chosen.
    $html = Livewire::withQueryParams(['tab' => 'campaigns', 'camp' => (string) $campaign->id])->test(TrafficSourcesPage::class)
        ->set('campaignDraft.spend_mode', 'price')
        ->html();
    expect($html)->toMatch('/rg-admin-date-time--date" wire:model="campaignDraft\.price_from"[^>]*x-data="\{\s+value: \'2026-11-17\',/')
        ->not->toContain('type="date"');

    // The day corrected on Spend: one of the network's last fourteen, in its own time zone — already the 17th's evening in Tokyo.
    $html = Livewire::withQueryParams(['tab' => 'spend'])->test(TrafficSourcesPage::class)->call('load')
        ->set('spendSource', (string) $network->id)
        ->html();
    expect($html)->toContain('id="rg-admin-spend-day"')
        ->toMatch('/rg-admin-date-time--date" wire:model="spendEntry\.day"\s+data-now="2026-11-17T21:00:00"\s+x-data="\{\s+value: \'\',\s+min: \'2026-11-04\',\s+max: \'2026-11-17\'/');
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

it('reads its period from the address — a preset or a range of network days — and the last 30 days for anything it cannot read', function () {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main']);
    TrafficVisit::factory()->create(['source_id' => $source->id, 'arrived_at' => '2026-11-03 10:00:00', 'network_day' => '2026-11-03', 'opened_at' => now()]);
    $open = fn (array $query) => Livewire::withQueryParams($query)->test(TrafficSourcesPage::class);
    $days = fn ($page): array => [$page->instance()->filter()->fromDay, $page->instance()->filter()->toDay];

    // The period picker the game Analytics shares, in the header band.
    $page = $open([])
        ->assertSeeInOrder(['Traffic sources', 'Last 30 days', '19 Oct — 17 Nov 2026', 'Yesterday', 'Last 7 days', 'Last 30 days', 'Last 90 days', 'Custom range'])
        ->assertDontSee('All time');
    expect($days($page))->toBe(['2026-10-19', '2026-11-17']);

    $page = $open(['from' => '2026-11-01', 'to' => '2026-11-05', 'tab' => 'value'])
        ->assertSet('from', '2026-11-01')
        ->assertViewHas('periodPicker', fn (array $picker): bool => $picker['label'] === 'Custom range' && $picker['dates'] === '01 Nov — 05 Nov 2026' && $picker['min'] === '2025-10-14' && $picker['max'] === '2026-11-17');
    expect($days($page))->toBe(['2026-11-01', '2026-11-05']);

    $yesterday = $open(['period' => 'yesterday']);
    expect($days($yesterday))->toBe(['2026-11-16', '2026-11-16']);

    // Reversed, past today, longer than visits are kept, no such day: the preset in the address, else the default.
    foreach ([['2026-11-05', '2026-11-01'], ['2026-11-10', '2026-11-18'], ['2025-10-13', '2026-11-17'], ['2026-04-31', '2026-05-02']] as [$from, $to]) {
        $fallen = $open(['period' => '7d', 'from' => $from, 'to' => $to])->assertSet('period', '7d')->assertSet('from', '')->assertSet('to', '');
        expect($days($fallen))->toBe(['2026-11-11', '2026-11-17']);
    }

    $open(['period' => 'all'])->assertSet('period', '30d');

    // A new period starts the list over and unticks what was ticked; a forged one is read the same way.
    $page->set('pageNumber', 3)->set('selected', ['1:site:40123'])
        ->call('applyRange', '2026-11-02', '2026-11-04')
        ->assertSet('pageNumber', 1)
        ->assertSet('selected', [])
        ->assertSet('to', '2026-11-04')
        ->call('choosePeriod', 'yesterday')
        ->assertSet('period', 'yesterday')
        ->assertSet('from', '')
        ->set('from', '2026-11-01')
        ->assertSet('from', '')
        ->assertSet('period', 'yesterday');
});
