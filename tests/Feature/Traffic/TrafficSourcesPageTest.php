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
use App\Enums\TrafficPostbackStatus;
use App\Enums\TrafficSourceStatus;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Filament\Support\ReportPeriodMemory;
use App\Models\Game;
use App\Models\TrafficApiConnection;
use App\Models\TrafficBlock;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficCampaign;
use App\Models\TrafficChanceRun;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficStepChance;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Support\Traffic\Blocking\BlockRule;
use App\Support\Traffic\Money\TrafficMoney;
use App\Support\Traffic\Postbacks\PostbackSchedule;
use App\Support\Traffic\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

it('says what the network answered a test postback, and why PopAds always refuses one', function () {
    bindFakeHostResolver(['pb.example.com' => ['93.184.216.40'], 'pb2.example.com' => ['93.184.216.41']]);
    Http::preventStrayRequests();
    // PopAds answers 200 with an empty body; its error code comes only in this header.
    Http::fake(['pb.example.com/*' => Http::response('', 200, ['X-PopAds-EC' => 'InvalidEnvironmentException-16-46'])]);

    $page = Livewire::test(TrafficSourcesPage::class)->call('openEditor', 'new:popads');
    $page->set('draft.rules.0.url_template', 'https://pb.example.com/cpixel.php?s2s=1&aid=abc&id={click_id}&value={value}')
        ->call('testRule', 0)
        ->assertDispatched('rg-admin-toast', fn (string $name, array $params): bool => $params['tone'] === 'error'
            && str_starts_with($params['message'], 'Test postback: rejected · HTTP 200 · ')
            && str_contains($params['message'], 'X-PopAds-EC: InvalidEnvironmentException-16-46')
            && str_contains($params['message'], 'PopAds refuses any click ID it did not issue'));

    // A network that took the test: its answer, and no word about refusing.
    Http::fake(['pb2.example.com/*' => Http::response('OK', 200)]);
    $page->call('openEditor', 'new:placement');
    $page->set('draft.rules', [['event' => 'goal', 'goal_code' => '', 'url_template' => 'https://pb2.example.com/t?id={click_id}', 'enabled' => true]])
        ->call('testRule', 0)
        ->assertDispatched('rg-admin-toast', fn (string $name, array $params): bool => $params['tone'] === 'success'
            && str_starts_with($params['message'], 'Test postback: ok · HTTP 200 · ')
            && str_ends_with($params['message'], ' · OK'));
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

it('refuses a blank IP database edition, keeping the one saved', function () {
    $before = TrafficSetting::current()->geo_edition;

    expect(fn () => app(SaveTrafficSettingsAction::class)->handle($this->admin, ['geo_edition' => ''], TrafficSetting::current()->lock_version))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey('geo_edition'));

    expect(TrafficSetting::current()->geo_edition)->toBe($before);
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
    // The same period, named even when it is the default, at the card where it is set, in a tab of its own.
    $value = e(TrafficSourcesPage::getUrl(['tab' => 'value', 'period' => '30d']).'#traffic-value-sources');

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

it('sets a campaign’s stage from its panel, its figures read only once the page is in a browser', function () {
    $campaign = TrafficCampaign::factory()->create();
    TrafficVisit::factory()->create(['source_id' => $campaign->source_id, 'campaign_id' => $campaign->id, 'arrived_at' => now(), 'network_day' => '2026-11-17', 'spend' => '0.25']);

    // Opened from a saved address: its stages to set at once, its results waiting for the page to be in a browser.
    $page = Livewire::withQueryParams(['camp' => $campaign->id])->test(TrafficSourcesPage::class)
        ->assertSee('Stage')
        ->assertSeeHtml('data-testid="traffic-campaign-results" aria-busy="true"')
        ->assertViewHas('campaign', fn (array $panel): bool => $panel['figures'] === null && $panel['stages'] === null);

    $page->call('load')
        ->assertSeeHtml('data-testid="traffic-campaign-results" aria-busy="false"')
        ->assertViewHas('campaign', fn (array $panel): bool => $panel['figures']->visits === 1 && $panel['stages'] === [])
        ->call('setStage', 'test')
        ->assertViewHas('campaign', fn (array $panel): bool => count($panel['stages']) === 1 && $panel['stages'][0]['stage'] === 'test' && $panel['stages'][0]['figures']->visits === 1);

    expect($campaign->fresh())->stage->toBe(TrafficCampaignStage::Test)->status->toBe(TrafficCampaignStatus::Active);
});

it('keeps a campaign’s draft as typed when its stage changes, it is archived or restored, or a change is refused, and saves it after', function () {
    $campaign = TrafficCampaign::factory()->create(['name' => 'US popunder']);

    $page = Livewire::withQueryParams(['camp' => $campaign->id])->test(TrafficSourcesPage::class)
        ->set('campaignDraft.name', 'Renamed')
        ->call('setStage', 'test')
        ->assertSet('campaignDraft.name', 'Renamed')
        ->call('saveCampaign')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Campaign saved');

    expect($campaign->fresh())->name->toBe('Renamed')->stage->toBe(TrafficCampaignStage::Test);

    // Archived, refused a stage while archived, restored: the draft stays as typed through each, and saves after.
    $page->set('campaignDraft.name', 'Renamed again')
        ->call('archiveCampaign')
        ->assertSet('campaignDraft.name', 'Renamed again')
        ->call('setStage', 'scale')
        ->assertDispatched('rg-admin-toast', message: 'This campaign is archived. Restore it first.', tone: 'error')
        ->assertSet('campaignDraft.name', 'Renamed again')
        ->call('archiveCampaign', true)
        ->assertSet('campaignDraft.name', 'Renamed again')
        ->call('saveCampaign')
        ->assertHasNoErrors();

    expect($campaign->fresh())->name->toBe('Renamed again')->status->toBe(TrafficCampaignStatus::Active)->stage->toBe(TrafficCampaignStage::Test);

    // Saved by someone else after it was opened: the stage still changes, and Save says so rather than overwrite their change.
    TrafficCampaign::query()->whereKey($campaign->id)->update(['name' => 'Theirs', 'lock_version' => $campaign->fresh()->lock_version + 1]);
    $page->set('campaignDraft.name', 'Mine')
        ->call('setStage', 'optimise')
        ->assertSet('campaignDraft.name', 'Mine')
        ->call('saveCampaign')
        ->assertDispatched('rg-admin-toast', message: 'Someone saved this campaign after you opened it. Reload to see their changes.', tone: 'error');

    expect($campaign->fresh())->name->toBe('Theirs')->stage->toBe(TrafficCampaignStage::Optimise);
});

it('refuses Save after a stage change when someone else saved the campaign between the page’s read and the change’s lock', function () {
    $campaign = TrafficCampaign::factory()->create(['name' => 'US popunder']);
    $opened = $campaign->fresh()->lock_version;

    $page = Livewire::withQueryParams(['camp' => $campaign->id])->test(TrafficSourcesPage::class)
        ->assertSet('campaignLockVersion', $opened)
        ->set('campaignDraft.name', 'Mine');

    // The other administrator's save lands right after the page reads the campaign, before the stage change locks it.
    $landed = false;
    TrafficCampaign::retrieved(function (TrafficCampaign $read) use (&$landed, $campaign, $opened): void {
        if ($landed || $read->id !== $campaign->id) {
            return;
        }

        $landed = true;
        TrafficCampaign::query()->whereKey($campaign->id)->update(['name' => 'Theirs', 'lock_version' => $opened + 1]);
    });

    $page->call('setStage', 'test')
        ->assertSet('campaignLockVersion', $opened)
        ->call('saveCampaign')
        ->assertDispatched('rg-admin-toast', message: 'Someone saved this campaign after you opened it. Reload to see their changes.', tone: 'error');

    expect($landed)->toBeTrue()
        ->and($campaign->fresh())->name->toBe('Theirs')->stage->toBe(TrafficCampaignStage::Test);
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
        ->call('load')
        ->assertSeeHtmlInOrder(['data-testid="traffic-campaign-results" aria-busy="false"', 'Results by stage', 'Visits', 'last 30 days', 'Cost / account', 'ROI'])
        ->call('toggleCampaignCountry', 'ca')
        ->call('toggleCampaignDevice', 'mobile')
        ->call('saveCampaign')
        ->assertHasNoErrors();

    expect($campaign->fresh())->countries->toBe(['US', 'CA'])->devices->toBe(['mobile']);

    $page->call('campaignReport')->assertSet('tab', 'report')->assertSet('campaignId', (string) $campaign->id)->assertSet('camp', '');
});

it('binds a campaign’s Pays per as the kit’s segmented control, and its stages as toggles the arrow keys never press', function () {
    $campaign = TrafficCampaign::factory()->create(['stage' => 'optimise', 'bid_model' => 'click']);

    $html = Livewire::withQueryParams(['tab' => 'campaigns', 'camp' => (string) $campaign->id])->test(TrafficSourcesPage::class)->call('load')->html();
    $payPer = Str::betweenFirst($html, 'aria-label="Pays per"', '</div>');
    // Each stage card writes a stage period and a line of History at once: a toggle, not a radio the arrow keys move.
    $stages = Str::betweenFirst($html, 'rg-admin-traffic__stage-cards', 'Results by stage');

    expect($html)->toMatch('/<div\s[^>]*class="rg-admin-segmented"[^>]*aria-label="Pays per"/')
        ->and($html)->toMatch('/<div\s[^>]*wire:model\.live="campaignDraft\.bid_model"[^>]*aria-label="Pays per"/')
        ->and($payPer)->toMatch('/aria-checked="false"\s+tabindex="-1"/')
        ->and($payPer)->toContain('1,000 impressions')
        ->and($stages)->not->toContain('role="radio"')
        ->and($stages)->not->toContain('aria-checked')
        ->and(substr_count($stages, 'aria-pressed="true"'))->toBe(1)
        ->and(substr_count($stages, 'aria-pressed="false"'))->toBe(3)
        ->and($stages)->toMatch('/aria-pressed="true"[^>]*wire:click="setStage\(\'optimise\'\)"/');
});

it('says which devices a campaign targets: pressed toggles where they are typed in, plain chips where the network reports them', function () {
    $typed = TrafficCampaign::factory()->create(['devices' => ['mobile']]);
    $reported = TrafficCampaign::factory()->create(['devices' => ['desktop']]);
    TrafficApiConnection::factory()->create(['source_id' => $reported->source_id]);
    $devices = fn (TrafficCampaign $campaign): string => Str::betweenFirst(Livewire::withQueryParams(['tab' => 'campaigns', 'camp' => (string) $campaign->id])->test(TrafficSourcesPage::class)->call('load')->html(), '<dt>Devices</dt>', '<dt>Format</dt>');

    expect($devices($typed))->toMatch('/<button type="button" aria-pressed="true"[^>]*wire:click="toggleCampaignDevice\(\'mobile\'\)"/')
        ->toMatch('/<button type="button" aria-pressed="false"[^>]*wire:click="toggleCampaignDevice\(\'desktop\'\)"/')
        ->and($devices($reported))->not->toContain('<button')
        ->toContain('Desktop')
        ->not->toContain('Mobile');
});

it('refuses a campaign target that is no amount by its field, drawing the panel against the source’s target meanwhile', function () {
    $source = TrafficSource::factory()->create(['target_cpa' => '0.40']);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $source->id]);

    $page = Livewire::withQueryParams(['tab' => 'campaigns', 'camp' => (string) $campaign->id])->test(TrafficSourcesPage::class)
        ->call('load')
        ->set('campaignDraft.target_cpa', '1,5')
        ->assertOk()
        ->assertSee('target $0.40')
        ->call('saveCampaign')
        ->assertHasErrors(['target_cpa'])
        ->assertSet('campaignDraft.target_cpa', '1,5');

    $page->assertSee($page->errors()->first('target_cpa'));

    expect($campaign->fresh()->target_cpa)->toBeNull();
});

it('shows the campaign panel’s refusals of its budget, prices, format and day by their fields', function (string $field, string $value) {
    $campaign = TrafficCampaign::factory()->create();

    $page = Livewire::withQueryParams(['tab' => 'campaigns', 'camp' => (string) $campaign->id])->test(TrafficSourcesPage::class)
        ->call('load')
        ->set('campaignDraft.spend_mode', 'price')
        ->set('campaignDraft.'.$field, $value)
        ->call('saveCampaign')
        ->assertHasErrors([$field])
        // Said aloud too: the field can be far down the panel from Save.
        ->assertDispatched('rg-admin-toast', message: 'Some fields need fixing before the campaign can be saved.', tone: 'error');

    $page->assertSee($page->errors()->first($field));
})->with([
    'daily budget' => ['daily_budget', 'abc'],
    'mobile price' => ['bid_mobile', 'abc'],
    'desktop price' => ['bid_desktop', '-1'],
    'format' => ['format', str_repeat('x', 61)],
    'price from' => ['price_from', 'someday'],
]);

it('keeps a price Value refused to correct, drawing no amount from it', function () {
    Livewire::withQueryParams(['tab' => 'value'])->test(TrafficSourcesPage::class)
        ->call('load')
        ->set('prices.post_bonus', '1,5')
        ->call('savePrices')
        ->assertOk()
        ->assertHasErrors(['post_bonus'])
        ->assertSet('prices.post_bonus', '1,5')
        ->assertSee('post × —');
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

    // The period picker the game Analytics shares, in the header band, with every report page's presets.
    $page = $open([])
        ->assertSeeInOrder(['Traffic sources', 'Last 30 days', '19 Oct — 17 Nov 2026', 'Today', 'Yesterday', 'Last 7 days', 'Last 30 days', 'Last 90 days', 'This month', 'Last month', 'All time', 'Custom range'])
        ->assertViewHas('periodPicker', fn (array $picker): bool => $picker['presets'] === ReportPeriod::PRESETS);
    expect($days($page))->toBe(['2026-10-19', '2026-11-17']);

    $page = $open(['from' => '2026-11-01', 'to' => '2026-11-05', 'tab' => 'value'])
        ->assertSet('from', '2026-11-01')
        ->assertViewHas('periodPicker', fn (array $picker): bool => $picker['label'] === 'Custom range' && $picker['dates'] === '01 Nov — 05 Nov 2026' && $picker['min'] === '2025-10-14' && $picker['max'] === '2026-11-17');
    expect($days($page))->toBe(['2026-11-01', '2026-11-05']);

    $yesterday = $open(['period' => 'yesterday']);
    expect($days($yesterday))->toBe(['2026-11-16', '2026-11-16']);

    // Today: today's network day alone, named by the picker as that one day.
    $today = $open(['period' => 'today'])->assertSet('period', 'today')
        ->assertViewHas('periodPicker', fn (array $picker): bool => array_key_first($picker['presets']) === 'today' && $picker['label'] === 'Today' && $picker['dates'] === '17 Nov 2026' && [$picker['from'], $picker['to']] === ['2026-11-17', '2026-11-17']);
    expect($days($today))->toBe(['2026-11-17', '2026-11-17']);

    // Reversed, past today, longer than visits are kept, no such day: the preset in the address, else the default.
    foreach ([['2026-11-05', '2026-11-01'], ['2026-11-10', '2026-11-18'], ['2025-10-13', '2026-11-17'], ['2026-04-31', '2026-05-02']] as [$from, $to]) {
        $fallen = $open(['period' => '7d', 'from' => $from, 'to' => $to])->assertSet('period', '7d')->assertSet('from', '')->assertSet('to', '');
        expect($days($fallen))->toBe(['2026-11-11', '2026-11-17']);
    }

    // A preset it doesn't offer: the default.
    $open(['period' => '365d'])->assertSet('period', '30d');

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

it('reads this month, last month and all time as network days — all time from the first visit’s, no further back than visits are kept — and counts the visits of those days', function () {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main']);
    // The first visit of all arrived early on 20 Sep, UTC, from a network whose day was still 19 Sep; then one in October and one in November.
    foreach ([['2026-09-20 03:00:00', '2026-09-19'], ['2026-10-15 10:00:00', '2026-10-15'], ['2026-11-03 10:00:00', '2026-11-03']] as [$arrived, $day]) {
        TrafficVisit::factory()->create(['source_id' => $source->id, 'arrived_at' => $arrived, 'network_day' => $day, 'opened_at' => $arrived]);
    }
    $open = fn (array $query) => Livewire::withQueryParams($query)->test(TrafficSourcesPage::class);
    $days = fn ($page): array => [$page->instance()->filter()->fromDay, $page->instance()->filter()->toDay];
    $visits = fn ($page): int => $page->call('load')->viewData('total')->visits;

    $cases = [
        'this-month' => [['2026-11-01', '2026-11-17'], 'This month', '01 Nov — 17 Nov 2026', 1],
        'last-month' => [['2026-10-01', '2026-10-31'], 'Last month', '01 Oct — 31 Oct 2026', 1],
        'all' => [['2026-09-19', '2026-11-17'], 'All time', '19 Sep — 17 Nov 2026', 3],
    ];

    foreach ($cases as $preset => [$read, $label, $dates, $count]) {
        $page = $open(['period' => $preset])->assertSet('period', $preset)->assertSet('from', '')
            ->assertViewHas('periodPicker', fn (array $picker): bool => $picker['value'] === $preset && $picker['label'] === $label && $picker['dates'] === $dates && [$picker['from'], $picker['to']] === $read);
        expect($days($page))->toBe($read, $preset)
            ->and($visits($page))->toBe($count, $preset);
    }

    // The picker's own calls read them the same way.
    expect($days($open([])->call('choosePeriod', 'last-month')->assertSet('period', 'last-month')))->toBe(['2026-10-01', '2026-10-31'])
        ->and($days($open([])->call('choosePeriod', 'all')->assertSet('period', 'all')))->toBe(['2026-09-19', '2026-11-17']);

    // A visit older than visits are kept, not yet cleared away: all time stops at the oldest day kept.
    TrafficVisit::factory()->create(['source_id' => $source->id, 'arrived_at' => '2025-01-01 10:00:00', 'network_day' => '2025-01-01', 'opened_at' => '2025-01-01 10:00:00']);
    expect($days($open(['period' => 'all'])))->toBe(['2025-10-14', '2026-11-17']);
});

it('opens on the period last chosen in its picker when the address names none, an address naming one winning and kept in turn', function () {
    $open = fn (array $query = []) => Livewire::withQueryParams($query)->test(TrafficSourcesPage::class);
    $days = fn ($page): array => [$page->instance()->filter()->fromDay, $page->instance()->filter()->toDay];

    // Nothing chosen yet: the default. A preset chosen, then a range on another tab: what an address naming none opens on.
    $open()->assertSet('period', '30d')->call('choosePeriod', 'today');
    expect($days($open()->assertSet('period', 'today')))->toBe(['2026-11-17', '2026-11-17']);
    $open(['tab' => 'value'])->call('applyRange', '2026-11-02', '2026-11-04');
    expect($days($open()->assertSet('from', '2026-11-02')->assertSet('to', '2026-11-04')))->toBe(['2026-11-02', '2026-11-04']);

    // An address naming a period wins — the default too, as a game's Analytics names it in its links — and is kept in turn.
    $open(['period' => '30d', 'tab' => 'report'])->assertSet('period', '30d')->assertSet('from', '');
    $open()->assertSet('period', '30d')->assertSet('from', '')->assertSet('to', '');
    $open(['period' => 'yesterday']);
    $open()->assertSet('period', 'yesterday');

    // Growth → Analytics keeps its own.
    Livewire::withQueryParams(['period' => '90d'])->test(GrowthAnalyticsPage::class);
    $open()->assertSet('period', 'yesterday');

    // A range now older than visits are kept, or a preset the page doesn't offer: the default, silently.
    $open(['from' => '2026-11-02', 'to' => '2026-11-04']);
    $this->travelTo(CarbonImmutable::parse('2027-12-31 12:00:00'));
    $open()->assertSet('period', '30d')->assertSet('from', '')->assertSet('to', '');
    ReportPeriodMemory::remember(TrafficSourcesPage::PERIOD_MEMORY, '365d', '', '');
    $open()->assertSet('period', '30d');

    // Every preset the picker offers is kept and opened on again, all time and the months too.
    foreach (['this-month', 'last-month', 'all'] as $preset) {
        $open()->call('choosePeriod', $preset);
        $open()->assertSet('period', $preset)->assertSet('from', '')->assertSet('to', '');
    }
});

it('names the period on screen in every tab’s link, so a tab opened in a new browser tab shows that period, not the one chosen last', function () {
    $hrefs = fn ($page): array => collect($page->viewData('tabs'))->mapWithKeys(fn (array $tab): array => [$tab['id'] => $tab['href']])->all();
    $page = Livewire::withQueryParams(['period' => 'yesterday', 'tab' => 'campaigns'])->test(TrafficSourcesPage::class);

    expect($hrefs($page))->toBe([
        'report' => TrafficSourcesPage::getUrl(['period' => 'yesterday']),
        'campaigns' => TrafficSourcesPage::getUrl(['tab' => 'campaigns', 'period' => 'yesterday']),
        'value' => TrafficSourcesPage::getUrl(['tab' => 'value', 'period' => 'yesterday']),
        'spend' => TrafficSourcesPage::getUrl(['tab' => 'spend', 'period' => 'yesterday']),
        'sources' => TrafficSourcesPage::getUrl(['tab' => 'sources', 'period' => 'yesterday']),
        'logs' => TrafficSourcesPage::getUrl(['tab' => 'logs', 'period' => 'yesterday']),
    ]);
    $page->assertSeeHtml('href="'.e(TrafficSourcesPage::getUrl(['tab' => 'logs', 'period' => 'yesterday'])).'"');

    // Another browser tab chooses a period meanwhile: the links still name this one's — a range by its days, as the page read it.
    Livewire::withQueryParams([])->test(TrafficSourcesPage::class)->call('choosePeriod', '90d');
    $page->call('applyRange', '2026-11-02', '2026-11-04');

    expect($hrefs($page)['value'])->toBe(TrafficSourcesPage::getUrl(['tab' => 'value', 'period' => '30d', 'from' => '2026-11-02', 'to' => '2026-11-04']))
        ->and($hrefs($page)['report'])->toBe(TrafficSourcesPage::getUrl(['period' => '30d', 'from' => '2026-11-02', 'to' => '2026-11-04']));

    // Opened from such a link, the tab reads that range, whatever was chosen last.
    Livewire::withQueryParams([])->test(TrafficSourcesPage::class)->call('choosePeriod', '90d');
    $opened = Livewire::withQueryParams(['tab' => 'value', 'period' => '30d', 'from' => '2026-11-02', 'to' => '2026-11-04'])->test(TrafficSourcesPage::class)
        ->assertSet('from', '2026-11-02')
        ->assertSet('to', '2026-11-04');

    expect([$opened->instance()->filter()->fromDay, $opened->instance()->filter()->toDay])->toBe(['2026-11-02', '2026-11-04']);
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
    // On some campaigns' lists, taken off them all in the panel, and one blocked in the panel, not by RateGuru.
    $block('s6', TrafficBlockLevel::Site, TrafficBlockMethod::Api, TrafficBlockNetworkState::Partial);
    $block('s7', TrafficBlockLevel::Site, TrafficBlockMethod::Api, TrafficBlockNetworkState::TakenOff);
    $block('s8', TrafficBlockLevel::Site, TrafficBlockMethod::Panel, TrafficBlockNetworkState::Applied);
    TrafficBlock::factory()->create(['level' => TrafficBlockLevel::Site, 'ref' => 'other']);
    // The rule's own entries in the blocks log: today's count against the cap, yesterday's not.
    $queued = fn (string $ref, CarbonInterface $at) => TrafficBlockEvent::query()->create(['source_id' => $source->id, 'kind' => 'queued', 'level' => TrafficBlockLevel::Site, 'ref' => $ref, 'ref_count' => 1, 'method' => TrafficBlockMethod::Auto, 'actor' => TrafficActor::BlockRule, 'ok' => true, 'occurred_at' => $at]);
    $queued('s3', now());
    $queued('s4', now()->subDay());
    TrafficSetting::recordBlockRuleRun(now()->subMinutes(3));

    Livewire::withQueryParams(['tab' => 'sources', 'edit' => (string) $source->id])->test(TrafficSourcesPage::class)
        ->assertViewHas('editor', fn (array $editor): bool => $editor['blocking']['counts'] === ['sites' => 7, 'zones' => 1, 'by_hand' => 5, 'by_rule' => 2, 'in_panel' => 1, 'marked' => 2, 'pending' => 1, 'applied' => 1, 'partial' => 1, 'failed' => 1, 'taken_off' => 1, 'removing' => 1, 'rule_today' => 1])
        ->assertSee('Every 5 minutes, through the network’s API, within the day’s cap.')
        ->assertSeeHtmlInOrder([
            'data-testid="traffic-source-blocking-status"',
            'In force', '<strong>8</strong>', '7 sites · 1 zone', '5 by hand · 2 by the rule · 1 in the PopAds panel',
            // Partly blocked counts as neither on the list nor failed.
            'Sent through the API', '<strong>1</strong>', 'on the network’s list · 1 on some campaigns’ lists only · 1 waiting · 1 failed', '2 marked here only, not sent · 1 taken off in the PopAds panel · 1 coming off',
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
    config(['traffic_presets.connectors.popads.verified' => false]);
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
    // A blocked site whose clicks came through one of its zones.
    TrafficVisit::factory()->create(['source_id' => $source->id, 'site_ref' => '41006', 'zone_ref' => 'z7', 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'spend' => '0.40']);
    $block($source, '41006', TrafficBlockLevel::Site);
    $open = fn (array $query) => Livewire::withQueryParams(['view' => 'sites', 'seg' => 'blocked', ...$query])->test(TrafficSourcesPage::class)->call('load');

    $open(['source' => (string) $source->id, 'list' => 'site', 'period' => 'yesterday'])
        ->assertViewHas('list', fn (array $list): bool => $list['counts']['blocked'] === 3 && collect($list['rows'])->pluck('ref')->sort()->values()->all() === ['41003', '41004', '41006'])
        ->assertDontSee('50001');
    // By zone it lists the zone blocks too, and a site block whose clicks show as its zones as a site row of its own.
    $open(['source' => (string) $source->id])
        ->assertViewHas('list', fn (array $list): bool => collect($list['rows'])->pluck('ref')->sort()->values()->all() === ['41003', '41004', '41006', 'z9']);
    // That site row has no figures, and its zone keeps its own.
    $open(['source' => (string) $source->id, 'seg' => 'all'])
        ->assertViewHas('list', fn (array $list): bool => ($rows = collect($list['rows'])->keyBy('key'))[$source->id.':site:41006']['level'] === 'site'
            && $rows[$source->id.':site:41006']['figures']->visits === 0
            && $rows[$source->id.':zone:z7']['figures']->visits === 1
            && ! $rows[$source->id.':zone:z7']['blocked']);
    // Blocks are a source's: a campaign filter lists only what had clicks.
    $open(['source' => (string) $source->id, 'list' => 'site', 'campaign' => (string) TrafficCampaign::factory()->create(['source_id' => $source->id])->id])
        ->assertViewHas('list', fn (array $list): bool => $list['counts']['blocked'] === 0);
});

it('counts every campaign of a source in its stage on Campaigns, and lists an older one among hundreds of newer ones', function () {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $paused = TrafficCampaign::factory()->create(['source_id' => $source->id, 'name' => 'Old paused', 'status' => TrafficCampaignStatus::Active, 'stage' => TrafficCampaignStage::Paused, 'first_click_at' => now()->subDays(20)]);
    TrafficCampaign::factory()->count(300)->create(['source_id' => $source->id, 'status' => TrafficCampaignStatus::Active, 'stage' => TrafficCampaignStage::Test, 'first_click_at' => now()->subDay()]);
    $rows = fn (array $groups): array => collect($groups)->flatMap(fn (array $group): array => $group['rows'])->pluck('id')->all();

    // Every running one: the 300 newest, and how many more there are.
    Livewire::withQueryParams(['tab' => 'campaigns', 'source' => (string) $source->id])->test(TrafficSourcesPage::class)->call('load')
        ->assertViewHas('stageCounts', fn (array $counts): bool => $counts['all'] === 301 && $counts['test'] === 300 && $counts['paused'] === 1)
        ->assertViewHas('campaignGroups', fn (array $groups): bool => count($rows($groups)) === 300 && ! in_array($paused->id, $rows($groups), true))
        ->assertViewHas('campaignsMore', 1)
        ->assertSee('The 300 newest are shown; 1 older campaign isn’t.', false);

    Livewire::withQueryParams(['tab' => 'campaigns', 'source' => (string) $source->id, 'stage' => 'paused'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSee('Old paused')
        ->assertViewHas('campaignGroups', fn (array $groups): bool => $rows($groups) === [$paused->id])
        ->assertViewHas('campaignsMore', 0);
});

it('counts one origin of a network’s own figures a day on Spend — its file over its API — in the source, its campaigns and the day alike', function () {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main', 'spend_mode' => 'api', 'reporting_timezone' => 'UTC']);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $source->id, 'external_id' => '881204', 'name' => 'US popunder']);
    // The day the network's API said $10 and its file $12: the file counts, as the day is shared out.
    $fact = fn (string $origin, string $amount) => TrafficSpendDay::query()->forceCreate(['source_id' => $source->id, 'campaign_id' => $campaign->id, 'network_day' => '2026-11-16', 'origin' => $origin, 'site_ref' => '', 'zone_ref' => '', 'amount' => $amount, 'counted' => $origin === 'csv', 'allocated_amount' => 0, 'allocated_at' => now()]);
    $fact('api', '10.00');
    $fact('csv', '12.00');
    // A day with no figure of the network's: $3 counted on its clicks, so reports count $15 in all.
    TrafficVisit::factory()->create(['source_id' => $source->id, 'campaign_id' => $campaign->id, 'arrived_at' => '2026-11-15 10:00:00', 'network_day' => '2026-11-15', 'spend' => '3.00']);

    Livewire::withQueryParams(['tab' => 'spend'])->test(TrafficSourcesPage::class)->call('load')
        ->set('spendSource', (string) $source->id)
        ->assertViewHas('spendRows', fn (array $rows): bool => $rows[0]['network'] === ['$12.00', 'Differs by 25.0%']
            && $rows[0]['campaigns'][0]['network'] === '$12.00')
        ->assertViewHas('dayRows', fn (array $days): bool => TrafficMoney::of(collect($days)->firstWhere('date', '2026-11-16')['network'])->isEqualTo('12'));
});

it('counts every campaign of a network on Spend, and reads their rows only for a source opened by campaign', function () {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main']);
    TrafficCampaign::factory()->count(50)->create(['source_id' => $source->id, 'first_click_at' => now()->subDays(3)]);
    $visited = TrafficCampaign::factory()->create(['source_id' => $source->id, 'external_id' => '881204', 'first_click_at' => now()]);
    TrafficVisit::factory()->create(['source_id' => $source->id, 'campaign_id' => $visited->id, 'arrived_at' => '2026-11-16 10:00:00', 'network_day' => '2026-11-16', 'spend' => '0.25']);

    // The only paid source opens by campaign to begin with.
    Livewire::withQueryParams(['tab' => 'spend'])->test(TrafficSourcesPage::class)->call('load')
        ->assertSee('Ad network · 51 campaigns')
        ->assertViewHas('spendRows', fn (array $rows): bool => $rows[0]['open'] && collect($rows[0]['campaigns'])->pluck('external')->all() === ['881204'])
        ->call('toggleSpendSource', $source->id)
        ->assertSee('Ad network · 51 campaigns')
        ->assertViewHas('spendRows', fn (array $rows): bool => ! $rows[0]['open'] && $rows[0]['campaigns'] === []);
});

it('counts on Sources only the postbacks of a source’s rules that are on as failing', function () {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $on = TrafficPostbackRule::factory()->create(['source_id' => $source->id, 'event' => TrafficEvent::Account, 'position' => 0]);
    $failed = fn (TrafficPostbackRule $rule) => TrafficPostback::factory()->create([
        'conversion_id' => TrafficConversion::factory()->create(['visit_id' => TrafficVisit::factory()->create(['source_id' => $source->id])->id])->id,
        'rule_id' => $rule->id, 'status' => TrafficPostbackStatus::Failed, 'attempts' => 6, 'http_status' => 503,
    ]);
    // Two rules switched off, each with a postback that failed this week.
    foreach ([1, 2] as $position) {
        $failed(TrafficPostbackRule::factory()->create(['source_id' => $source->id, 'enabled' => false, 'position' => $position]));
    }
    $postbacks = fn (): array => Livewire::withQueryParams(['tab' => 'sources'])->test(TrafficSourcesPage::class)->call('load')->viewData('networks')[0]['postbacks'];

    expect($postbacks()['text'])->toBe('1 postback · delivering');

    $failed($on);

    expect($postbacks())->text->toBe('1 of 1 postback failing')->sub->toBe('HTTP 503 · attempt 6 of '.(count(PostbackSchedule::RETRY_AFTER) + 1));
});

it('keeps the country groups as typed when the prices are saved, and the prices as typed when the groups are', function () {
    $page = Livewire::withQueryParams(['tab' => 'value'])->test(TrafficSourcesPage::class)
        ->set('groups.0.name', 'Top markets')
        ->set('prices.account_price', '0.65')
        ->call('savePrices')
        ->assertHasNoErrors()
        ->assertSet('groups.0.name', 'Top markets')
        ->assertSet('prices.account_price', '0.65')
        ->assertSet('pricesFrom', TrafficPriceSet::current()->id);

    $page->set('prices.post_bonus', '1.25')
        ->call('saveGroups')
        ->assertHasNoErrors()
        ->assertSet('prices.post_bonus', '1.25')
        ->assertSet('pricesFrom', TrafficPriceSet::current()->id);

    expect(TrafficPriceSet::current())->account_price->toBe('0.6500')
        ->and(TrafficPriceSet::current()->country_groups[0]['name'])->toBe('Top markets');

    // The price typed meanwhile saves on the set the groups made.
    $page->call('savePrices')->assertHasNoErrors();

    expect(TrafficPriceSet::current())->post_bonus->toBe('1.2500')->account_price->toBe('0.6500')
        ->and(TrafficPriceSet::current()->country_groups[0]['name'])->toBe('Top markets');
});
