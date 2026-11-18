<?php

use App\Actions\Traffic\BlockTrafficRefsAction;
use App\Actions\Traffic\SaveTrafficSettingsAction;
use App\Enums\TrafficActor;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Enums\TrafficBlockVerdict;
use App\Enums\TrafficCampaignStage;
use App\Enums\TrafficCampaignStatus;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficConnector;
use App\Enums\TrafficEvent;
use App\Enums\TrafficSourceStatus;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\Game;
use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficCampaign;
use App\Models\TrafficChanceRun;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficStepChance;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Support\Traffic\Blocking\BlockRule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

it('names the network’s goal ID for what it is, and says a goal and an account postback may both fire for one click', function () {
    $address = fn (string $goal): string => 'https://s.magsrv.com/tag.php?goal='.$goal.'&tag={click_id}&value={value}';
    $page = Livewire::test(TrafficSourcesPage::class)
        ->call('openEditor', 'new:exoclick')
        ->call('addRule')
        ->set('draft.rules.0.event', 'goal')
        ->set('draft.rules.0.goal_code', '123456')
        ->set('draft.rules.0.url_template', $address('{goal_code}'))
        ->set('draft.rules.0.enabled', true)
        ->assertSee('Goal ID in the network')
        ->assertSee('is the goal each game’s flow sets', false)
        ->assertSee('is the ID the network gave the goal this postback reports')
        ->assertDontSee('happen together, so the network gets both')
        ->call('addRule')
        ->set('draft.rules.1.event', 'account')
        ->set('draft.rules.1.goal_code', '123457')
        ->set('draft.rules.1.url_template', $address('{goal_code}'))
        ->set('draft.rules.1.enabled', true)
        ->assertSee('“Flow goal reached” and “New account confirmed” happen together, so the network gets both', false);

    // Under one goal ID the warning about counting a click twice says it instead.
    $page->set('draft.rules.1.goal_code', '123456')
        ->assertDontSee('happen together, so the network gets both')
        ->assertSee('go to the same goal of the network');
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

it('shows on Blocking what a source blocked, where its blocks stand with the network and the rule’s day, with the ways to them', function () {
    config(['traffic_presets.connectors.popads.verified' => true]);
    $source = TrafficSource::factory()->create(['name' => 'PopAds main', 'connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site, 'block_auto' => true, 'block_daily_cap' => 5]);
    TrafficApiConnection::factory()->create(['source_id' => $source->id]);
    $block = fn (string $ref, TrafficBlockLevel $level, TrafficBlockMethod $method, TrafficBlockNetworkState $state, ?CarbonInterface $at = null) => TrafficBlock::factory()->create(['source_id' => $source->id, 'ref' => $ref, 'level' => $level, 'method' => $method, 'network_state' => $state, 'blocked_at' => $at ?? now()]);
    $block('s1', TrafficBlockLevel::Site, TrafficBlockMethod::Manual, TrafficBlockNetworkState::ByHand);
    $block('s2', TrafficBlockLevel::Site, TrafficBlockMethod::Api, TrafficBlockNetworkState::Applied);
    $block('s3', TrafficBlockLevel::Site, TrafficBlockMethod::Auto, TrafficBlockNetworkState::Pending);
    $block('s4', TrafficBlockLevel::Site, TrafficBlockMethod::Auto, TrafficBlockNetworkState::Failed, now()->subDay());
    $block('z1', TrafficBlockLevel::Zone, TrafficBlockMethod::Manual, TrafficBlockNetworkState::ByHand);
    // On its way off the network's list: no longer in force.
    $block('s5', TrafficBlockLevel::Site, TrafficBlockMethod::Api, TrafficBlockNetworkState::Removing);
    TrafficBlock::factory()->create(['level' => TrafficBlockLevel::Site, 'ref' => 'other']);
    // The rule's own entries in the blocks log: today's count against the cap, yesterday's not.
    $queued = fn (string $ref, CarbonInterface $at) => TrafficBlockEvent::query()->create(['source_id' => $source->id, 'kind' => 'queued', 'level' => TrafficBlockLevel::Site, 'ref' => $ref, 'ref_count' => 1, 'method' => TrafficBlockMethod::Auto, 'actor' => TrafficActor::BlockRule, 'ok' => true, 'occurred_at' => $at]);
    $queued('s3', now());
    $queued('s4', now()->subDay());
    TrafficSetting::recordBlockRuleRun(now()->subMinutes(3));

    Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $source->id])->test(TrafficSourcesPage::class)
        ->assertViewHas('editor', fn (array $editor): bool => $editor['blocking']['counts'] === ['sites' => 4, 'zones' => 1, 'by_hand' => 3, 'by_rule' => 2, 'marked' => 2, 'pending' => 1, 'applied' => 1, 'failed' => 1, 'removing' => 1, 'rule_today' => 1])
        ->assertSee('Every 5 minutes, through the network’s API, within the day’s cap.')
        ->assertSeeHtmlInOrder([
            'data-testid="traffic-source-blocking-status"',
            'In force', '<strong>5</strong>', '4 sites · 1 zone', '3 by hand · 2 by the rule',
            'Sent through the API', '<strong>1</strong>', 'on the network’s list · 1 waiting · 1 failed', '2 marked here only, not sent · 1 coming off',
            'By the rule today', '1 of 5', 'On for this source', 'Last ran 17 Nov 11:57',
            // A zone among its blocks: the list by zone, which lists zone and site blocks alike.
            'href="'.e(TrafficSourcesPage::getUrl(['view' => 'sites', 'source' => $source->id, 'seg' => 'blocked']).'#traffic-performance').'"', 'See blocked in Sites &amp; zones',
            'href="'.e(TrafficSourcesPage::getUrl(['tab' => 'logs', 'log' => 'blocks', 'source' => $source->id])).'"', 'Open the blocks log',
        ])
        // Everything the rule needs is in place: nothing to explain.
        ->assertDontSee('traffic-source-blocking-why');
});

it('opens a source’s blocked zones in the list it blocks them in, whatever the period on screen, and says when the rule has not run', function () {
    $source = TrafficSource::factory()->create(['block_level' => TrafficBlockLevel::Zone]);

    Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $source->id, 'period' => '7d'])->test(TrafficSourcesPage::class)
        ->assertSeeHtmlInOrder(['Nothing blocked yet', '0 by hand · 0 by the rule', '0 of 10', 'Off for this source', 'Hasn’t run yet'])
        ->assertSee('href="'.e(TrafficSourcesPage::getUrl(['view' => 'sites', 'source' => $source->id, 'seg' => 'blocked']).'#traffic-performance').'"', false);

    // A new source has nothing blocked to show yet, and a placement no Blocking pane.
    Livewire::test(TrafficSourcesPage::class)->call('openEditor', 'new:popads')->assertDontSee('traffic-source-blocking-status');
    Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) TrafficSource::factory()->placement()->create()->id])->test(TrafficSourcesPage::class)
        ->assertViewHas('editor', fn (array $editor): bool => $editor['blocking'] === null);
});

it('explains on Blocking how blocking works, with the rule’s thresholds as set and whether the worth is known yet', function () {
    TrafficSetting::current()->forceFill(['block_bot_clicks' => 45, 'block_open_below' => 40, 'block_min_spend' => '3.50', 'block_min_clicks' => 150, 'block_roi_below' => -25])->save();
    $source = TrafficSource::factory()->create(['block_level' => TrafficBlockLevel::Zone, 'block_daily_cap' => 7]);
    $open = fn () => Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $source->id])->test(TrafficSourcesPage::class);

    $open()->assertSeeHtmlInOrder([
        'data-testid="traffic-source-blocking-how"', 'How blocking works',
        'Every 5 minutes the rule reads each zone’s last 30 days.',
        'at least 45 clicks, and fewer opened the page than 40% and than 50% of the source’s own average, or than 5% always, whatever the average.',
        'ROI below −25% once a zone has cost enough — at least $3.50 spent or 150 clicks, as neither this source nor its campaigns have a target price of an account — from 3 days after its first click, so its accounts can arrive; and only once the worth is known: 0 of the 30 accounts it needs so far.',
        'only those with a click in the last 3 days, the most spent first, up to 7 a day; one you unblock stays unblocked for 30 days.',
        'These are the general thresholds, from Report → Worth blocking: give this source its own under Thresholds above.',
        'You block by hand in Report → Sites &amp; zones',
        'With the API connected, a block reaches the network within a minute; otherwise copy the IDs into the network’s blacklist.',
    ]);

    // In a browser it reads the source's last 30 days: no clicks yet, so the rule's own share counts.
    $open()->call('load')->assertSee('of the source’s own average, or than 5% always, whatever the average: no clicks from it in the last 30 days yet, so 40% alone counts.');

    $run = TrafficChanceRun::query()->forceCreate(['status' => 'finished', 'reason' => 'nightly', 'price_set_id' => TrafficPriceSet::current()->id, 'matured_before' => now(), 'started_at' => now(), 'finished_at' => now()]);
    TrafficStepChance::query()->forceCreate(['run_id' => $run->id, 'slice_key' => '*', 'step' => 'opened', 'visitors' => 900, 'accounts' => 42, 'slice_accounts' => 42, 'chance' => '0.04666667', 'thin' => false, 'stands_on' => '*']);

    $open()->assertSee('after its first click, so its accounts can arrive; judged now that the worth stands on 42 accounts.');
});

it('explains on Blocking the thresholds that apply to a source — its own marked — against its own average and its target price of an account', function () {
    $source = TrafficSource::factory()->create(['block_level' => TrafficBlockLevel::Site, 'target_cpa' => '0.60', 'block_bot_clicks' => 45, 'block_roi_below' => -25, 'block_grace_days' => 5, 'block_target_multiple' => '3']);
    // Pop traffic: 40 of its 100 clicks in the last 30 days opened the page.
    TrafficVisit::factory()->count(100)->sequence(fn ($sequence): array => ['opened_at' => $sequence->index < 40 ? now() : null])
        ->create(['source_id' => $source->id, 'site_ref' => '40123', 'arrived_at' => now()->subDays(2), 'network_day' => now()->subDays(2)->toDateString()]);

    Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $source->id])->test(TrafficSourcesPage::class)->call('load')
        ->assertViewHas('editor', fn (array $editor): bool => $editor['blocking']['own'] === ['block_bot_clicks', 'block_roi_below', 'block_grace_days', 'block_target_multiple']
            && $editor['blocking']['average']['below'] === 20.0)
        ->assertSeeHtmlInOrder([
            'data-testid="traffic-source-blocking-how"',
            'at least 45 clicks (this source’s), and fewer opened the page than 50% and than 50% of the source’s own average, or than 5% always, whatever the average: its clicks opened the page 40% of the time, so under 20% counts.',
            'ROI below −25% (this source’s) once a site has cost enough — 3 (this source’s) × the target price of an account: its campaign’s own where it has one, else this source’s $0.60, so $1.80 — from 5 days (this source’s) after its first click',
            'Those marked (this source’s) are its own, set under Thresholds above; the rest are the general ones from Report → Worth blocking.',
        ]);
});

it('keeps a network on the general block rule, or saves its own thresholds with it, refused as the general ones are', function () {
    TrafficSetting::current()->forceFill(['block_bot_clicks' => 35])->save();
    $source = TrafficSource::factory()->create(['name' => 'PopAds main', 'block_level' => TrafficBlockLevel::Site]);
    $page = Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $source->id])->test(TrafficSourcesPage::class)
        ->assertViewHas('editor', fn (array $editor): bool => $editor['thresholds']['own'] === false && $editor['thresholds']['general']['block_bot_clicks'] === '35' && $editor['thresholds']['general']['block_target_multiple'] === '2'
            && $editor['thresholds']['general']['block_min_spend'] === '2.00')
        ->assertSeeHtmlInOrder([
            'data-testid="traffic-source-thresholds"', 'General rule', 'Own thresholds for this source',
            '<strong>Likely bots</strong>', 'placeholder="35"', 'aria-label="Clicks before bots show"', 'wire:model="draft.source.block_bot_clicks"',
            '<strong>Losing</strong>', 'placeholder="2"', 'wire:model="draft.source.block_target_multiple"', '<strong x-show="thresholds === \'general\'">$2.00</strong>', 'placeholder="2.00"', 'wire:model="draft.source.block_min_spend"',
            'placeholder="3"', 'wire:model="draft.source.block_grace_days"',
            '<strong>Weak start</strong>', 'wire:model="draft.source.block_start_percent"',
        ]);

    // Refused by its fields, as Worth blocking refuses the general ones; nothing saved.
    $page->set('draft.source.block_open_below', 0)->set('draft.source.block_roi_below', 10)->set('draft.source.block_target_multiple', '0.2')->set('draft.source.block_min_spend', 'lots')
        ->call('saveSource')
        ->assertHasErrors(['block_open_below', 'block_roi_below', 'block_target_multiple', 'block_min_spend'])
        ->assertSee('The opened below, percent field must be at least 1.');
    // Each refused field marked so, the rest as they were.
    expect($page->html())->toMatch('/<input class="[^"]*rg-admin-input--invalid"[^>]*aria-label="Opened below, percent"[^>]*aria-invalid="true"/')
        ->not->toMatch('/<input class="[^"]*rg-admin-input--invalid"[^>]*aria-label="Clicks before bots show"/')
        ->and($source->fresh()->block_open_below)->toBeNull();

    // A multiple keeps one decimal place, and says so.
    $page->set('draft.source.block_target_multiple', '1.25')->call('saveSource')
        ->assertHasErrors(['block_target_multiple'])
        ->assertSee('Use a number from 0.5 to 20 with at most one decimal place, such as 1.5.');

    $page->set('draft.source.block_open_below', '')->set('draft.source.block_roi_below', -25)->set('draft.source.block_target_multiple', '1.5')->set('draft.source.block_min_spend', '3.5')->set('draft.source.block_bot_clicks', '45')
        ->call('saveSource')
        ->assertHasNoErrors()
        ->assertViewHas('editor', fn (array $editor): bool => $editor['thresholds']['own'] === true);

    expect($source->fresh()->only(BlockRule::KEYS))->toBe([
        'block_bot_clicks' => 45, 'block_open_below' => null, 'block_open_below_average' => null, 'block_open_floor' => null, 'block_min_spend' => '3.50', 'block_min_clicks' => null,
        'block_roi_below' => -25, 'block_grace_days' => null, 'block_target_multiple' => '1.5', 'block_start_clicks' => null, 'block_start_percent' => null,
    ]);

    // Saved by someone else meanwhile: the editor's draft is refused whole.
    $source->fresh()->forceFill(['lock_version' => $source->fresh()->lock_version + 1])->save();
    $page->set('draft.source.block_bot_clicks', '50')->call('saveSource')
        ->assertDispatched('rg-admin-toast', message: 'Someone saved this source after you opened it. Reload to see their changes.', tone: 'error');
    expect($source->fresh()->block_bot_clicks)->toBe(45);

    // Back on the general rule — the switch empties every one of its own in the draft — saved with the source.
    $page->call('openEditor', (string) $source->id);

    foreach (BlockRule::KEYS as $key) {
        $page->set('draft.source.'.$key, null);
    }

    $page->call('saveSource')->assertHasNoErrors();
    expect(array_filter($source->fresh()->only(BlockRule::KEYS), fn (mixed $value): bool => $value !== null))->toBe([]);
});

it('explains on Blocking when the floor is the line for a source that is nearly all bots, its own floor marked, and when it has none', function () {
    $source = TrafficSource::factory()->create(['block_level' => TrafficBlockLevel::Site]);
    // Nearly all bots: 3 of its 100 clicks in the last 30 days opened the page, so half its average holds nothing.
    TrafficVisit::factory()->count(100)->sequence(fn ($sequence): array => ['opened_at' => $sequence->index < 3 ? now() : null])
        ->create(['source_id' => $source->id, 'site_ref' => '40123', 'arrived_at' => now()->subDays(2), 'network_day' => now()->subDays(2)->toDateString()]);
    $open = fn () => Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $source->id])->test(TrafficSourcesPage::class)->call('load');

    $open()->assertViewHas('editor', fn (array $editor): bool => $editor['blocking']['average']['below'] === 5.0 && $editor['blocking']['average']['fromFloor'] === true)
        ->assertSee('of the source’s own average, or than 5% always, whatever the average: its clicks opened the page 3% of the time, so the floor counts, under 5%.');

    $source->forceFill(['block_open_floor' => 10])->save();
    $open()->assertSee('of the source’s own average, or than 10% (this source’s) always, whatever the average: its clicks opened the page 3% of the time, so the floor counts, under 10%.');

    // Its floor off: half its average is the line again, and the step says there is no floor.
    $source->forceFill(['block_open_floor' => 0])->save();
    $open()->assertViewHas('editor', fn (array $editor): bool => $editor['blocking']['average']['below'] === 1.5 && $editor['blocking']['average']['fromFloor'] === false)
        ->assertSee('of the source’s own average, with no floor (this source’s): its clicks opened the page 3% of the time, so under 1.5% counts.');
});

it('saves a network’s own floor with it — 0 turning the general one off for it — refused outside 0 to 100', function () {
    TrafficSetting::current()->forceFill(['block_open_floor' => 8])->save();
    $source = TrafficSource::factory()->create(['name' => 'PopAds main', 'block_level' => TrafficBlockLevel::Site]);
    $page = Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $source->id])->test(TrafficSourcesPage::class)
        ->assertViewHas('editor', fn (array $editor): bool => $editor['thresholds']['general']['block_open_floor'] === '8')
        ->assertSeeHtmlInOrder([
            'data-testid="traffic-source-thresholds"', '<strong>Likely bots</strong>',
            'wire:model="draft.source.block_open_below_average"', 'of the source’s own average, or than',
            '<strong x-show="thresholds === \'general\'">8%</strong>', 'placeholder="8"', 'aria-label="Opened below, percent, whatever the source’s average"', 'wire:model="draft.source.block_open_floor"', 'whatever the average — 0 for no floor.',
            '<strong>Losing</strong>',
        ]);

    $page->set('draft.source.block_open_floor', 101)->call('saveSource')
        ->assertHasErrors(['block_open_floor'])
        ->assertSee('The opened below, percent, whatever the source’s average field must not be greater than 100.');
    expect($page->html())->toMatch('/<input class="[^"]*rg-admin-input--invalid"[^>]*aria-label="Opened below, percent, whatever the source’s average"[^>]*aria-invalid="true"/')
        ->and($source->fresh()->block_open_floor)->toBeNull();

    $page->set('draft.source.block_open_floor', '0')->call('saveSource')->assertHasNoErrors()
        ->assertViewHas('editor', fn (array $editor): bool => $editor['thresholds']['own'] === true);
    expect($source->fresh()->block_open_floor)->toBe(0)
        ->and(BlockRule::own($source->fresh()->only(BlockRule::KEYS)))->toBe(['block_open_floor']);

    $page->set('draft.source.block_open_floor', '12')->call('saveSource')->assertHasNoErrors();
    expect($source->fresh()->block_open_floor)->toBe(12);

    // Emptied, it keeps the general floor again.
    $page->set('draft.source.block_open_floor', '')->call('saveSource')->assertHasNoErrors();
    expect($source->fresh()->block_open_floor)->toBeNull();
});

it('keeps no thresholds of its own for a banner, and gives a new network the general rule to start from', function () {
    $banner = TrafficSource::factory()->placement()->create();

    Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $banner->id])->test(TrafficSourcesPage::class)
        ->assertViewHas('editor', fn (array $editor): bool => $editor['thresholds'] === null)
        ->assertDontSee('traffic-source-thresholds');

    Livewire::test(TrafficSourcesPage::class)->call('openEditor', 'new:popads')
        ->assertViewHas('editor', fn (array $editor): bool => $editor['thresholds']['own'] === false)
        ->set('draft.source.name', 'PopAds main')
        ->set('draft.source.block_bot_clicks', '60')
        ->call('saveSource')
        ->assertHasNoErrors();

    expect(TrafficSource::query()->where('name', 'PopAds main')->sole()->block_bot_clicks)->toBe(60);
});

it('says on Blocking why the rule can’t block for a source by itself', function (array $source, ?TrafficConnectionStatus $connection, bool $verified, string $why) {
    config(['traffic_presets.connectors.popads.verified' => $verified]);
    $source = TrafficSource::factory()->create(['connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site, 'block_auto' => true, ...$source]);

    if ($connection !== null) {
        TrafficApiConnection::factory()->create(['source_id' => $source->id, 'status' => $connection]);
    }

    Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $source->id])->test(TrafficSourcesPage::class)
        ->assertViewHas('editor', fn (array $editor): bool => $editor['blocking']['why'] === [$why])
        ->assertSeeHtmlInOrder(['data-testid="traffic-source-blocking-why"', 'The rule can’t block for this source by itself:', '<li>'.e($why).'</li>']);
})->with([
    'a paused source' => [['status' => TrafficSourceStatus::Paused], TrafficConnectionStatus::Connected, true, 'The source is paused: the rule blocks only for an active source.'],
    'an ended source' => [['status' => TrafficSourceStatus::Ended], TrafficConnectionStatus::Connected, true, 'The source has ended: the rule blocks only for an active source.'],
    'a network RateGuru has no API for' => [['connector' => null], null, true, 'RateGuru has no API for this network: block by hand and copy the IDs into its blacklist.'],
    'no API connected' => [[], null, true, 'No API connected: connect it below.'],
    'a disconnected API' => [[], TrafficConnectionStatus::Disconnected, true, 'No API connected: connect it below.'],
    'a connection that needs attention' => [[], TrafficConnectionStatus::NeedsAttention, true, 'The API connection needs attention: reconnect it below.'],
    'an unverified connector' => [[], TrafficConnectionStatus::Connected, false, 'The PopAds API isn’t verified on a real account yet, so the rule doesn’t block through it.'],
    'an API that can’t block the level' => [['block_level' => TrafficBlockLevel::Zone], TrafficConnectionStatus::Connected, true, 'The PopAds API can’t block zones: block by site, or by hand.'],
]);

it('says on Blocking what the rule would need, as a note, while the source doesn’t ask it to block', function () {
    $source = TrafficSource::factory()->create(['connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site, 'block_auto' => false]);

    Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $source->id])->test(TrafficSourcesPage::class)
        ->assertSee('Only you block, from Report → Sites &amp; zones.', false)
        ->assertSeeHtmlInOrder(['rg-admin-notice--info', 'The rule couldn’t block for this source by itself:', '<li>No API connected: connect it below.</li>', '<li>The PopAds API isn’t verified on a real account yet']);
});

it('says on Blocking only how many a source without an API marked, since RateGuru can’t see the network’s blacklist', function () {
    $source = TrafficSource::factory()->create(['connector' => null, 'block_level' => TrafficBlockLevel::Site]);
    TrafficBlock::factory()->create(['source_id' => $source->id, 'level' => TrafficBlockLevel::Site, 'ref' => 's1', 'method' => TrafficBlockMethod::Manual, 'network_state' => TrafficBlockNetworkState::ByHand, 'blocked_at' => now()]);

    Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $source->id])->test(TrafficSourcesPage::class)
        ->assertSeeHtmlInOrder(['Marked here', '<strong>1</strong>', 'No API sends these: copy their IDs into the network’s blacklist. RateGuru can’t see that list.'])
        ->assertDontSee('Sent through the API')
        ->assertDontSee('waiting ·');
});

it('keeps on Blocking the rule’s blocks today against the cap after an administrator takes one off', function () {
    config(['traffic_presets.connectors.popads.verified' => true]);
    $source = TrafficSource::factory()->create(['connector' => TrafficConnector::PopAds, 'block_level' => TrafficBlockLevel::Site, 'block_auto' => true, 'block_daily_cap' => 2]);
    TrafficApiConnection::factory()->create(['source_id' => $source->id]);
    $blocking = app(BlockTrafficRefsAction::class);
    $blocking->block(null, $source, TrafficBlockLevel::Site, ['s1', 's2'], null, true, TrafficBlockMethod::Auto, TrafficBlockVerdict::Bots, 'Block rule');
    $blocking->unblock($this->admin, TrafficBlock::query()->where('ref', 's1')->sole());
    // The sync takes it off the network's list and drops it.
    TrafficBlock::query()->where('ref', 's1')->delete();

    Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $source->id])->test(TrafficSourcesPage::class)
        ->assertSeeHtmlInOrder(['In force', '<strong>1</strong>', 'By the rule today', '2 of 2']);
});

it('lists under Blocked every block in force of a source, with no figures for one that sent no click in the period', function () {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main', 'block_level' => TrafficBlockLevel::Site]);
    $other = TrafficSource::factory()->create(['name' => 'Other network']);
    TrafficVisit::factory()->create(['source_id' => $source->id, 'site_ref' => '41003', 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'spend' => '0.25']);
    $block = fn (TrafficSource $owner, string $ref, TrafficBlockLevel $level, TrafficBlockNetworkState $state = TrafficBlockNetworkState::ByHand) => TrafficBlock::factory()->create(['source_id' => $owner->id, 'ref' => $ref, 'level' => $level, 'method' => TrafficBlockMethod::Manual, 'network_state' => $state, 'blocked_at' => now()->subDays(40)]);
    $block($source, '41003', TrafficBlockLevel::Site);
    // Blocked 40 days ago and quiet since: no visit in any period.
    $block($source, '41004', TrafficBlockLevel::Site);
    $block($source, 'z9', TrafficBlockLevel::Zone);
    // Coming off the network's list: no longer in force.
    $block($source, '41005', TrafficBlockLevel::Site, TrafficBlockNetworkState::Removing);
    $block($other, '50001', TrafficBlockLevel::Site);
    $open = fn (array $query) => Livewire::withQueryParams(['view' => 'sites', 'seg' => 'blocked', ...$query])->test(TrafficSourcesPage::class)->call('load');

    $open(['source' => (string) $source->id, 'list' => 'site', 'period' => 'yesterday'])
        ->assertViewHas('list', fn (array $list): bool => $list['counts']['blocked'] === 2 && collect($list['rows'])->pluck('ref')->sort()->values()->all() === ['41003', '41004'])
        ->assertDontSee('50001');
    // By zone it lists the zone blocks too.
    $open(['source' => (string) $source->id])
        ->assertViewHas('list', fn (array $list): bool => collect($list['rows'])->pluck('ref')->sort()->values()->all() === ['41003', '41004', 'z9']);
    // Blocks are a source's: a campaign filter lists only what had clicks.
    $open(['source' => (string) $source->id, 'list' => 'site', 'campaign' => (string) TrafficCampaign::factory()->create(['source_id' => $source->id])->id])
        ->assertViewHas('list', fn (array $list): bool => $list['counts']['blocked'] === 0);
});
