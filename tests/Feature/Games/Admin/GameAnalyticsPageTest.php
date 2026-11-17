<?php

use App\Actions\Games\ApplyGameTrafficAction;
use App\Actions\Games\ChangeGameFlowAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Filament\Pages\TrafficSourcesPage;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\GameAnalytics;
use App\Livewire\Games\PlayGame;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\ProjectSettings;
use App\Models\TrafficSetting;
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

it('lists only the traffic periods the version chosen had a share in, with every share but that version’s figures alone', function () {
    $game = $this->draft['game'];
    $periods = fn () => GameTrafficPeriod::query()->where('game_id', $game->id)->where('audience', GameAudience::Anonymous->value)->orderByDesc('id')->get();
    // A period someone was assigned in is kept when the shares change; an empty one is changed in place.
    $assign = fn (string $guest, GameVersion $to): GameAssignment => GameAssignment::query()->forceCreate([
        'game_id' => $game->id, 'audience' => GameAudience::Anonymous, 'visitor_hash' => hash('sha256', $guest),
        'version_id' => $to->id, 'period_id' => $periods()->first()->id, 'assigned_at' => now(), 'last_seen_at' => now(),
    ]);
    $this->travelTo('2026-11-10 12:00:00');
    $v1 = liveGame($this->draft);
    $assign('guest-z', $v1);
    $this->travelTo('2026-11-17 12:00:00');
    $v2 = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $game->fresh(), $this->draft['set']->id, $this->draft['flow']->id);
    app(ApplyGameTrafficAction::class)->handle($this->draft['admin'], $game, GameAudience::Anonymous, [$v1->id => 50, $v2->id => 50]);
    $assign('guest-a', $v1);
    $b = $assign('guest-b', $v2);
    $this->travelTo('2026-11-18 12:00:00');
    app(ApplyGameTrafficAction::class)->handle($this->draft['admin'], $game, GameAudience::Anonymous, [$v2->id => 100]);
    $c = $assign('guest-c', $v2);
    GameAttempt::factory()->create(['version_id' => $v2->id, 'assignment_id' => $b->id, 'visitor_hash' => $b->visitor_hash, 'status' => GameAttemptStatus::Completed, 'completed_at' => now()]);
    GameAttempt::factory()->create(['version_id' => $v2->id, 'assignment_id' => $c->id, 'visitor_hash' => $c->visitor_hash]);
    $this->actingAs($this->draft['admin']);

    $page = Livewire::test(GameAnalytics::class, ['record' => $game->id]);

    // Every version: every period, each with all it assigned and started.
    expect($periods())->toHaveCount(3)
        ->and(collect($page->viewData('periodRows'))->pluck('figures')->all())->toBe([
            '1 assigned · 1 started · 0 completed',
            '2 assigned · 1 started · 1 completed',
            '1 assigned · 0 started · 0 completed',
        ]);

    // V1 had no share in the newest period: only the two it had, with its own figures, and every share still there.
    $page->set('version', $v1->id)->assertSee('Traffic periods · Anonymous visitors · V1');
    expect(collect($page->viewData('periodRows'))->map(fn (array $row): string => $row['shares'].' | '.$row['figures'])->all())->toBe([
        'V1 50% · V2 50% | V1: 1 assigned · 0 started · 0 completed',
        'V1 100% | V1: 1 assigned · 0 started · 0 completed',
    ]);

    $page->set('version', $v2->id);
    expect(collect($page->viewData('periodRows'))->pluck('figures')->all())->toBe([
        'V2: 1 assigned · 1 started · 0 completed',
        'V2: 1 assigned · 1 started · 1 completed',
    ]);

    // Logged-in players were only ever sent to V1.
    $page->set('audience', 'authenticated')
        ->assertSee('V2 had no share of logged-in players in any period yet.')
        ->assertDontSee('No periods yet for this group.');
});

it('counts the anonymous sign-ups from the game’s recorded visits, those never confirmed and still waiting too, of the version chosen', function () {
    $first = liveGame($this->draft);
    $second = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game']->fresh(), $this->draft['set']->id, $this->draft['flow']->id);
    $visit = fn (GameVersion $version, array $state) => TrafficVisit::factory()->create(['game_id' => $this->draft['game']->id, 'version_id' => $version->id, 'opened_at' => now(), ...$state]);
    // V1: one confirmed, one signed up ten days ago and never confirmed, one still waiting, one who never signed up.
    $visit($first, ['signup_at' => now(), 'account_at' => now()]);
    $visit($first, ['arrived_at' => now()->subDays(10), 'network_day' => now()->subDays(10)->toDateString(), 'signup_at' => now()->subDays(10)]);
    $visit($first, ['signup_at' => now()->subDay()]);
    $visit($first, []);
    // V2: one confirmed. Before the window: not counted.
    $visit($second, ['signup_at' => now(), 'account_at' => now()]);
    $visit($first, ['arrived_at' => now()->subDays(40), 'network_day' => now()->subDays(40)->toDateString(), 'signup_at' => now()->subDays(40)]);

    $this->actingAs($this->draft['admin']);
    $figures = fn ($page): array => collect($page->viewData('signups')['figures'])->pluck('value', 'label')->all();

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertSeeInOrder(['Sign-ups', 'Counted from the visits of the game Traffic sources recorded in this period', 'Signed up', '4', 'Of 5 recorded visits']);

    expect($figures($page))->toBe(['Signed up' => '4', 'Confirmed' => '2', 'Never confirmed' => '1', 'Still waiting' => '1'])
        // Recording is off now, though these visits were recorded: it says so, and where to switch it on.
        ->and($page->viewData('signups')['note'])->toContain('Record visits is off now')
        ->and($page->viewData('signups')['trafficUrl'])->toBe(TrafficSourcesPage::getUrl(['tab' => 'sources']))
        // The funnel keeps its own steps: its counts are the game's, these the visits'.
        ->and(collect($page->viewData('funnel'))->pluck('label')->all())->toBe(['Visitors', 'Game started', 'Completed', 'Sign-up begun', 'Goal reached', 'Account confirmed']);

    $page->set('version', $first->id)->assertSee('Counted from the visits of this version');
    expect($figures($page))->toBe(['Signed up' => '3', 'Confirmed' => '1', 'Never confirmed' => '1', 'Still waiting' => '1']);

    $page->set('version', $second->id);
    expect($figures($page))->toBe(['Signed up' => '1', 'Confirmed' => '1', 'Never confirmed' => '0', 'Still waiting' => '0']);

    // Logged-in players never sign up: their funnel is as it was, without the card.
    $page->set('audience', 'authenticated')->assertViewHas('signups', null)->assertDontSee('Counted from the visits');
});

it('shows dashes, never a nought, and where visits are recorded, while the game has no recorded visit', function () {
    liveGame($this->draft);
    $this->actingAs($this->draft['admin']);

    $page = Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertSee('Visits aren’t recorded, so there are no sign-ups to count. Switch on Record visits under Traffic sources → Sources')
        ->assertSee('Record visits');

    expect($page->viewData('signups'))->toMatchArray(['tracked' => false, 'trafficUrl' => TrafficSourcesPage::getUrl(['tab' => 'sources'])])
        ->and(collect($page->viewData('signups')['figures'])->pluck('value')->unique()->all())->toBe(['—']);

    // Recording, but nobody of this game came: still nothing to count, and it says why.
    TrafficSetting::current()->forceFill(['capture_enabled' => true])->save();
    TrafficVisit::factory()->create(['signup_at' => now()]);

    Livewire::test(GameAnalytics::class, ['record' => $this->draft['game']->id])
        ->assertSee('No visit of the game was recorded in this period')
        ->assertViewHas('signups', fn (array $signups): bool => $signups['tracked'] === false && $signups['figures'][0]['value'] === '—' && $signups['trafficUrl'] === null);
});
