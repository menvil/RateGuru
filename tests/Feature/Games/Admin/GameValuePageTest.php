<?php

use App\Enums\TrafficEvent;
use App\Enums\TrafficStepValueMode;
use App\Filament\Pages\TrafficSourcesPage;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\GameValue;
use App\Models\ProjectSettings;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSource;
use App\Models\TrafficStepValue;
use App\Models\TrafficVisit;
use App\Queries\Traffic\GameWorthQuery;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(3);
    liveGame($this->draft);
    $this->actingAs($this->draft['admin']);
});

it('lists every step of a play in its part, with the halfway round and the goal marked', function () {
    $this->get(GameResource::getUrl('value', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSee('What each step is worth')
        ->assertSee('Not worked out yet')
        ->assertSeeInOrder(['Playing', 'Page opened', 'Game started', 'Round 1 answered', 'Round 2 answered', 'Round 3 answered · game finished', 'After the result', 'Email left', 'On the site', 'Opened the post', 'Accounts', 'Signed up', 'Account confirmed', 'Existing account signed in'])
        ->assertSee('Halfway')
        ->assertSee('Goal of ')
        ->assertSee('No paid traffic yet.')
        ->assertSee('No postbacks are on.');
});

it('works a step out per slice, says what the slice stands on, and shows a new visitor’s worth on the tab', function () {
    $game = $this->draft['game'];
    finishedChanceRun([
        'g'.$game->id => ['opened' => ['chance' => 0.1, 'visitors' => 200], 'started' => ['chance' => 0.2, 'visitors' => 120]],
        'g'.$game->id.'|d:mobile' => ['opened' => ['chance' => 0.08, 'visitors' => 150]],
    ], sliceAccounts: 12);

    Livewire::test(GameValue::class, ['record' => $game->id])
        ->assertSee('$0.050')
        ->assertSee('60%')
        ->assertSee('Only 12 confirmed accounts in '.$game->title.' (fewer than 30)')
        ->set('device', 'mobile')
        ->assertSee('Only 12 confirmed accounts in '.$game->title.' · mobile (fewer than 30)')
        ->assertSee('$0.040');
});

it('counts a new visitor’s worth on the tab at the share of the country groups its visitors came from, as the steps do', function () {
    $game = $this->draft['game'];
    TrafficPriceSet::factory()->create(['country_groups' => [
        ['key' => 't1', 'name' => 'Tier 1', 'percent' => 100, 'countries' => ['US']],
        ['key' => 'rest', 'name' => 'Every other country', 'percent' => 30, 'countries' => []],
    ]]);
    // Every visitor counted came from the 30 % group.
    finishedChanceRun([
        'g'.$game->id => ['opened' => ['chance' => 0.1, 'visitors' => 200]],
        'g'.$game->id.'|k:rest' => ['opened' => ['chance' => 0.1, 'visitors' => 200]],
    ]);
    $worth = app(GameWorthQuery::class);
    $badge = fn (string $value): Closure => fn (array $header): bool => collect($header['tabs'])->firstWhere('key', 'value')['badge'] === $value;

    // $0.50 an account × 10 % × 30 %.
    expect((string) $worth->visitor($game))->toBe((string) $worth->slice($game, $worth->rounds($game))['steps']['opened']['value'])
        ->and((string) $worth->visitor($game))->toBe('0.01500000');
    Livewire::test(GameValue::class, ['record' => $game->id])->assertViewHas('header', $badge('$0.015'));

    // A run that counted no visitor in any group gives the full share.
    finishedChanceRun(['g'.$game->id => ['opened' => ['chance' => 0.1, 'visitors' => 200]]]);

    expect((string) $worth->visitor($game))->toBe('0.05000000');
    Livewire::test(GameValue::class, ['record' => $game->id])->assertViewHas('header', $badge('$0.050'));
});

it('sets a step’s value by hand in place, and goes back to the worked-out value', function () {
    $game = $this->draft['game'];

    Livewire::test(GameValue::class, ['record' => $game->id])
        ->call('editStep', 'started')
        ->assertSet('editing', 'started')
        ->assertSee('One worth for every source, device and country of '.$game->title.'.')
        ->set('editValue', '$0.03')
        ->call('saveValue')
        ->assertDispatched('rg-admin-toast', message: 'Game started set to $0.0300 by hand. Postbacks and fresh traffic use it from now on; finished periods keep their real outcomes.')
        ->assertSet('editing', null)
        ->assertSee('Set by hand')
        ->assertSee('worked out $0.0000');

    $value = TrafficStepValue::query()->sole();

    expect($value->value)->toBe('0.0300')->and($value->mode)->toBe(TrafficStepValueMode::Always);

    Livewire::test(GameValue::class, ['record' => $game->id])
        ->call('editStep', 'started')
        ->assertSet('editValue', '0.03')
        ->call('clearValue')
        ->assertDispatched('rg-admin-toast', message: 'Game started is worked out again: $0.0000 here.');

    expect(TrafficStepValue::query()->count())->toBe(0);
});

it('never opens the account or a sign-in for setting by hand, and links both to the prices of the whole site', function () {
    Livewire::test(GameValue::class, ['record' => $this->draft['game']->id])
        ->call('editStep', 'account')
        ->assertSet('editing', null)
        ->call('editStep', 'login')
        ->assertSet('editing', null)
        ->assertDontSeeHtml('data-testid="game-value-edit-account"')
        ->assertDontSeeHtml('data-testid="game-value-edit-login"');

    $prices = e(TrafficSourcesPage::getUrl(['tab' => 'value']));

    $this->get(GameResource::getUrl('value', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSeeHtmlInOrder(['data-testid="game-value-account"', 'href="'.$prices.'"', 'Set for the whole site', 'data-testid="game-value-login"', 'href="'.$prices.'"', 'Set for the whole site'])
        // A step settable here has no such link.
        ->assertDontSeeHtml('data-testid="game-value-site-started"');
});

it('shows what a sign-in to an existing account is worth, at the price of the whole site and the country group’s share', function () {
    TrafficPriceSet::factory()->create(['login_price' => '0.4', 'country_groups' => [
        ['key' => 't1', 'name' => 'Tier 1', 'percent' => 100, 'countries' => ['US']],
        ['key' => 'rest', 'name' => 'Every other country', 'percent' => 30, 'countries' => []],
    ]]);

    Livewire::test(GameValue::class, ['record' => $this->draft['game']->id])
        ->assertSeeHtmlInOrder(['data-testid="game-value-login"', 'Existing account signed in', 'signed in after the click', '$0.400'])
        ->assertViewHas('rows', fn (array $rows): bool => collect($rows['accounts'])->firstWhere('step', 'login')['worth'] === '0.40000000')
        ->set('group', 'rest')
        ->assertViewHas('rows', fn (array $rows): bool => collect($rows['accounts'])->firstWhere('step', 'login')['worth'] === '0.12000000');
});

it('prices an account at the source’s own price with the source filter, and mixes the sources’ prices in for every source, saying so', function () {
    $game = $this->draft['game'];
    $exo = TrafficSource::factory()->create(['name' => 'ExoClick main']);
    $pop = TrafficSource::factory()->create(['name' => 'PopAds main']);
    TrafficPriceSet::factory()->create(['source_account_prices' => [$exo->id => '0.8000']]);
    // 10 of the game's 40 accounts came from ExoClick.
    finishedChanceRun([
        'g'.$game->id => ['opened' => ['chance' => 0.1, 'visitors' => 400, 'accounts' => 40]],
        'g'.$game->id.'|s'.$exo->id => ['opened' => ['chance' => 0.1, 'visitors' => 100, 'accounts' => 10]],
    ]);
    $account = fn (string $worth): Closure => fn (array $rows): bool => collect($rows['accounts'])->firstWhere('step', 'account')['worth'] === $worth;

    Livewire::test(GameValue::class, ['record' => $game->id])
        // (10 × $0.80 + 30 × $0.50) / 40 accounts.
        ->assertViewHas('rows', $account('0.57500000'))
        ->assertViewHas('rows', fn (array $rows): bool => collect($rows['playing'])->firstWhere('step', 'opened')['worth'] === '0.05750000')
        ->assertSee('Sources’ own prices mixed in')
        ->assertSeeHtml('data-testid="game-value-price-note"')
        ->assertSee('ExoClick main has an account price of its own, mixed in here as its accounts came: $0.58 an account rather than the site’s $0.50.')
        ->assertSee('Mixed price $0.58')
        ->set('source', 's'.$exo->id)
        ->assertViewHas('rows', $account('0.80000000'))
        ->assertViewHas('rows', fn (array $rows): bool => collect($rows['playing'])->firstWhere('step', 'opened')['worth'] === '0.08000000')
        ->assertSee('Own price of this source')
        ->assertSee('ExoClick main counts an account at its own price, $0.80, not the site’s $0.50.')
        ->assertSee('Its own price $0.80')
        ->assertViewHas('bySource', fn (array $rows): bool => collect($rows)->firstWhere('key', 'all')['account'] === '0.57500000')
        ->set('source', 's'.$pop->id)
        ->assertViewHas('rows', $account('0.50000000'))
        ->assertDontSeeHtml('data-testid="game-value-price-note"')
        ->set('source', 'c:search')
        ->assertViewHas('rows', $account('0.50000000'));
});

it('shows the game’s sources, its paid traffic and the postbacks that send its steps', function () {
    $game = $this->draft['game'];
    $source = TrafficSource::factory()->create(['name' => 'PopAds main']);
    TrafficPostbackRule::factory()->create(['source_id' => $source->id, 'event' => 'started', 'goal_code' => '81442']);
    TrafficVisit::factory()->count(2)->create(['source_id' => $source->id, 'game_id' => $game->id, 'arrived_at' => now()->subDay(), 'opened_at' => now()->subDay(), 'spend' => '0.10']);

    Livewire::test(GameValue::class, ['record' => $game->id])
        ->assertSee('PopAds main · Game started')
        ->assertSee('Goal 81442 · by device and country group')
        ->assertSee('Postback · PopAds main 81442')
        ->assertSee('$0.20')
        ->call('pickSource', 's'.$source->id)
        ->assertSet('source', 's'.$source->id);
});

it('shows the game’s paid traffic by source on its analytics, and compares its versions', function () {
    $source = TrafficSource::factory()->create(['name' => 'PopAds main']);
    TrafficVisit::factory()->count(3)->create(['source_id' => $source->id, 'game_id' => $this->draft['game']->id, 'arrived_at' => now()->subDay(), 'opened_at' => now()->subDay(), 'spend' => '0.10']);

    $this->get(GameResource::getUrl('analytics', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSee('Versions on paid traffic')
        ->assertSee('No paid visitor reached a version in this period.')
        ->assertSeeInOrder(['Traffic sources', 'Ad networks', 'PopAds main', '3 visits']);
});

it('lists a Visit settled postback with what it can send, without tagging every step', function () {
    $source = TrafficSource::factory()->create(['name' => 'ExoClick tests']);
    TrafficPostbackRule::factory()->create(['source_id' => $source->id, 'event' => TrafficEvent::Settled, 'needs_event' => TrafficEvent::Completed, 'url_template' => 'https://s.magsrv.com/tag.php?goal=abc&tag={click_id}&value={value}', 'enabled' => true]);

    $this->get(GameResource::getUrl('value', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSeeInOrder(['ExoClick tests', 'Visit settled'])
        ->assertDontSee('No postbacks are on.')
        ->assertDontSee('Postback · ExoClick tests');
});
