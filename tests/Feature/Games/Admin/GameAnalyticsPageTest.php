<?php

use App\Actions\Games\ChangeGameFlowAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Enums\GameAudience;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\GameAnalytics;
use App\Livewire\Games\PlayGame;
use App\Models\GameAttempt;
use App\Models\ProjectSettings;
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
        ->assertSeeInOrder(['Started', '1', '50.0% of visitors'])
        ->assertSeeInOrder(['Completion', '100.0%', '1 completed'])
        ->assertSee('No version asks for an email')
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
        ->toBe(['Visitors' => '2', 'Game started' => '1', 'Completed' => '1', 'Sign-up begun' => '0', 'New account' => '0'])
        // Each rate names what it is a share of: signing up is of the people who finished, not of those who began it.
        ->and(collect($page->viewData('funnel'))->pluck('rateOf', 'label')->all())
        ->toBe(['Visitors' => null, 'Game started' => 'of Visitors', 'Completed' => 'of Game started', 'Sign-up begun' => 'of Completed', 'New account' => 'of Completed']);

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
        ->assertSeeInOrder(['Started', '1', '100.0% of visitors'])
        ->assertSeeInOrder(['Completion', '100.0%', '2 of 2 plays completed']);
    $funnel = collect($page->viewData('funnel'));

    expect($funnel->pluck('count', 'label')->all())->toBe(['Visitors' => '1', 'Game started' => '1', 'Completed' => '1', 'Sign-up begun' => '1', 'New account' => '0'])
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
        ->assertSeeInOrder(['Email capture', '100.0%', '1 email'])
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
    $started = fn ($page): string => collect($page->viewData('kpis'))->firstWhere('label', 'Started')['value'];

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
        ->assertSee('This version doesn’t ask for an email')
        ->assertDontSee('Email captured');

    expect(collect($page->viewData('kpis'))->firstWhere('label', 'Email capture')['value'])->toBe('—');
});
