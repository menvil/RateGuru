<?php

use App\Actions\Games\ChangeGameFlowAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Enums\GameAudience;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\GameAnalytics;
use App\Livewire\Games\PlayGame;
use App\Models\GameAttempt;
use App\Models\ProjectSettings;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Support\Games\Flow\GameFlowTemplate;
use Livewire\Livewire;

/**
 * Growth → Games → Analytics reads what playing recorded: two guests open a
 * live game, one plays it through, and the tab shows exactly that — the
 * figures, the funnel, the round reached, the result earned — per group, and
 * nothing for a group nobody of which came.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
});

it('says there is nothing to measure before a version is published', function () {
    $this->actingAs($this->draft['admin'])
        ->get(GameResource::getUrl('analytics', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSee('Nothing to measure yet')
        ->assertSee('Go to Versions');
});

it('says nobody came yet while a live game has no visitors', function () {
    liveGame($this->draft);

    $this->actingAs($this->draft['admin'])
        ->get(GameResource::getUrl('analytics', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSee('No visitors here yet')
        ->assertSee('Traffic periods · Anonymous visitors');
});

it('shows what two guests did: one opened the game, the other played it through', function () {
    $version = liveGame($this->draft);
    $cookie = (string) config('games.visitor_cookie.name');

    Livewire::withCookie($cookie, str_repeat('a', 40))->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->assertSee('data-testid="game-step-full_result"', false);
    session()->flush();
    Livewire::withCookie($cookie, str_repeat('b', 40))->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init');
    session()->flush();

    $this->actingAs($this->draft['admin']);
    $profile = $version->read()->profileFor(1);

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertSee('Anonymous visitors · Last 30 days · all versions')
        ->assertSeeInOrder(['Visitors', '2', 'People who opened the game'])
        ->assertSeeInOrder(['Game started', '1', '50.0% of visitors'])
        ->assertSeeInOrder(['Completed', '1', '100.0% of starters'])
        ->assertSeeInOrder(['Goal reached', '0', '0.0% of completed'])
        // No traffic was recorded, so there is no worth to show.
        ->assertSee('No visits recorded in Traffic sources')
        // Nobody came in the 30 days before, so there is nothing to move against.
        ->assertSee('No earlier data')
        // No version asks for an email, so the funnel has no step for it.
        ->assertDontSee('Email captured')
        ->assertSee('Rounds reached · V1')
        ->assertSee('V1 has the most attempts here')
        ->assertSeeInOrder(['Round 1', '100.0%'])
        ->assertSeeInOrder(['Results · V1', $profile->name->english(), '100.0%'])
        ->assertSeeInOrder(['Side by side', 'V1', $this->draft['set']->name.' · '.$this->draft['flow']->name])
        ->assertSee('1 started · 1 completed');

    // A row's bar comes before its count, so the funnel is read from its rows rather than the markup.
    expect(collect($page->viewData('funnel'))->pluck('count', 'label')->all())
        ->toBe(['Visitors' => '2', 'Game started' => '1', 'Completed' => '1', 'Sign-up begun' => '0', 'Goal reached' => '0', 'Account confirmed' => '0'])
        // Each rate names what it is a share of: signing up is of the people who finished, not of those who began it.
        ->and(collect($page->viewData('funnel'))->pluck('rateOf', 'label')->all())
        ->toBe(['Visitors' => null, 'Game started' => 'of Visitors', 'Completed' => 'of Game started', 'Sign-up begun' => 'of Completed', 'Goal reached' => 'of Game started', 'Account confirmed' => 'of Completed']);

    // The funnel counts people, and says so.
    $page->assertSee('each counted once')
        ->assertDontSee('counts the attempts started')
        ->assertSeeHtml('<span class="rg-admin-table__meta">of Game started</span>');

    // Split by flow: the flow and the versions made from it.
    $page->set('breakdown', 'flow')
        ->assertSeeInOrder([$this->draft['flow']->name, 'Used by V1'])
        // Nobody signed in came.
        ->set('audience', 'authenticated')
        ->assertSee('No visitors here yet')
        ->assertSee('Traffic periods · Logged-in players');
});

it('counts people where it counts people: a guest who played twice started once, and began signing up by email', function () {
    liveGame($this->draft);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('a', 40))->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->call('signUpStarted', 'email')
        ->call('replay')->call('choose', 1)->call('next')
        ->assertSee('data-testid="game-step-full_result"', false);
    session()->flush();
    $this->actingAs($this->draft['admin']);

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertSeeInOrder(['Game started', '1', '100.0% of visitors'])
        ->assertSeeInOrder(['Completed', '1', '100.0% of starters']);
    $funnel = collect($page->viewData('funnel'));

    expect($funnel->pluck('count', 'label')->all())->toBe(['Visitors' => '1', 'Game started' => '1', 'Completed' => '1', 'Sign-up begun' => '1', 'Goal reached' => '0', 'Account confirmed' => '0'])
        ->and($funnel->firstWhere('label', 'Sign-up begun')['hint'])->toBe('Email 1');
});

it('counts the emails of versions that ask for one', function () {
    $this->draft['flow'] = app(ChangeGameFlowAction::class)->create($this->draft['admin'], $this->draft['game'], 'Email first', GameFlowTemplate::EmailBeforeResult);
    liveGame($this->draft);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('d', 40))->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')->call('continue')
        ->set('email', 'guest@example.test')->call('submitEmail')
        ->assertSee('data-testid="game-step-full_result"', false);
    session()->flush();
    $this->actingAs($this->draft['admin']);

    Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertSeeInOrder(['Email captured', '1', '100.0%']);
});

it('narrows the figures to one version, and to the window asked for', function () {
    $version = liveGame($this->draft);
    $cookie = (string) config('games.visitor_cookie.name');

    $this->travelTo(now()->subDays(20));
    Livewire::withCookie($cookie, str_repeat('c', 40))->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue');
    session()->flush();
    $this->travelBack();

    // V2, without traffic, with another guest's attempt started now.
    $second = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game']->fresh(), $this->draft['set']->id, $this->draft['flow']->id);
    GameAttempt::factory()->create(['version_id' => $second->id]);

    $this->actingAs($this->draft['admin']);
    $started = fn ($page): string => collect($page->viewData('kpis'))->firstWhere('label', 'Game started')['value'];

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id]);
    expect($started($page))->toBe('2');

    $page->set('version', $version->id)
        ->assertSee('Anonymous visitors · Last 30 days · V1');
    expect($started($page))->toBe('1');

    $page->set('period', '7d')
        ->assertSee('No visitors here yet')
        ->set('version', 0)
        ->assertDontSee('No visitors here yet');
    expect($started($page))->toBe('1');
});

it('names what each rate of the logged-in funnel is a share of: playing again is of the players who started', function () {
    $version = liveGame($this->draft);
    $player = User::factory()->create();
    GameAttempt::factory()->count(2)->create(['version_id' => $version->id, 'audience' => GameAudience::Authenticated, 'user_id' => $player->id, 'visitor_hash' => null]);

    $this->actingAs($this->draft['admin']);

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->set('audience', 'authenticated')
        ->assertSeeHtml('<span class="rg-admin-table__meta">of Game started</span>');
    $funnel = collect($page->viewData('funnel'))->keyBy('label');

    expect($funnel['Played again'])->toMatchArray(['count' => '1', 'rate' => '100.0%', 'rateOf' => 'of Game started'])
        ->and($funnel['Shared result']['rateOf'])->toBe('of Completed');
});

it('is only for those who manage games', function () {
    liveGame($this->draft);

    $this->actingAs(User::factory()->create())
        ->get(GameResource::getUrl('analytics', ['record' => $this->draft['game']]))
        ->assertForbidden();
});

it('leaves the emails out for a version chosen that asks for none, though another version does', function () {
    $emailFirst = app(ChangeGameFlowAction::class)->create($this->draft['admin'], $this->draft['game'], 'Email first', GameFlowTemplate::EmailBeforeResult);
    app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game'], $this->draft['set']->id, $emailFirst->id);
    $version = liveGame($this->draft);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('e', 40))->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->assertSee('data-testid="game-step-full_result"', false);
    session()->flush();
    $this->actingAs($this->draft['admin']);

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->set('version', $version->id)
        ->assertSee('Anonymous visitors · Last 30 days · V2')
        ->assertDontSee('Email captured');

    expect(collect($page->viewData('funnel'))->pluck('label'))->not->toContain('Email captured');
});

it('compares the versions on paid traffic and shows every visitor by where they came from', function () {
    $first = liveGame($this->draft);
    $second = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game']->fresh(), $this->draft['set']->id, $this->draft['flow']->id);
    $network = TrafficSource::factory()->create(['name' => 'PopAds main']);
    $banner = TrafficSource::factory()->create(['name' => 'Cat blog', 'type' => 'placement', 'place_site' => 'catblog.example']);
    $visits = function (int $count, array $state): void {
        TrafficVisit::factory()->count($count)->create(['game_id' => $this->draft['game']->id, 'opened_at' => now(), ...$state]);
    };

    // V1: 400 new visitors from the network and 5 from the banner, 40 accounts; V2: 400, 12 — a gap that is not chance.
    $visits(40, ['source_id' => $network->id, 'version_id' => $first->id, 'started_at' => now(), 'completed_at' => now(), 'goal_at' => now(), 'account_at' => now(), 'spend' => '0.5', 'value_real' => '0.5']);
    $visits(360, ['source_id' => $network->id, 'version_id' => $first->id, 'spend' => '0.01']);
    $visits(12, ['source_id' => $network->id, 'version_id' => $second->id, 'account_at' => now(), 'spend' => '0.5', 'value_real' => '0.5', 'device' => TrafficDevice::Desktop]);
    $visits(388, ['source_id' => $network->id, 'version_id' => $second->id, 'spend' => '0.01', 'device' => TrafficDevice::Desktop]);
    $visits(5, ['channel' => TrafficChannel::Placement, 'source_id' => $banner->id, 'click_hash' => null, 'version_id' => $first->id]);
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->count(3)->create(['game_id' => $this->draft['game']->id, 'opened_at' => now(), 'version_id' => $first->id]);
    // A visit of the window before moves the value against it.
    $visits(1, ['source_id' => $network->id, 'version_id' => $first->id, 'arrived_at' => now()->subDays(40), 'network_day' => now()->subDays(40)->toDateString(), 'value_real' => '0.5']);

    $this->actingAs($this->draft['admin']);

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertSee('Versions on paid traffic')
        ->assertSee('V1 brings more accounts')
        ->assertSee('9.9% of new visitors end up a confirmed account on V1, against 3.0% on V2')
        ->assertSeeInOrder(['V1 · '.$this->draft['flow']->name, 'Ahead', '405', '40'])
        ->assertSee('Traffic sources')
        ->assertSeeInOrder(['Ad networks', '1 source', 'PopAds main', 'Banners &amp; links', 'Cat blog', 'catblog.example', 'Unpaid', 'Search', 'All visitors', '808'], false);

    // One network only: the banner's visitors leave the comparison; one device only: the desktop visitors leave the sources.
    $page->call('$set', 'paidSource', $banner->id)
        ->assertDontSee('V1 brings more accounts')
        ->set('sourcesDevice', 'mobile')
        ->assertSeeInOrder(['All visitors', '408']);

    expect(collect($page->viewData('sources')['groups'])->pluck('name')->all())->toBe(['Ad networks', 'Banners & links', 'Unpaid']);
});
