<?php

use App\Enums\GameStatus;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\ListGames;
use App\Livewire\Games\PlayGame;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
});

it('lists the games, each with its mechanic’s icon, and offers no way to make one', function () {
    Game::factory()->create(['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']);

    $this->get(GameResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Run interactive games')
        ->assertSee('Trust Your Taste')
        ->assertSee('/games/trust-your-taste')
        ->assertSee('<span class="rg-admin-games__glyph" title="Versus">', false)
        ->assertSee('Not published yet')
        ->assertDontSee('New game')
        ->assertDontSee('/admin/games/create');
});

it('says when there are no games yet', function () {
    $this->get(GameResource::getUrl('index'))
        ->assertOk()
        ->assertSee('No games yet')
        ->assertDontSee('New game');
});

it('shows a game\'s newest version and whether it has traffic', function () {
    $quiet = GameVersion::factory()->create(['number' => 3]);
    // An older version made after it, so the number decides which is newest, not the order they were made in.
    GameVersion::factory()->create(['game_id' => $quiet->game_id, 'number' => 2]);
    $live = liveGame(publishableGameDraft(1));

    $this->get(GameResource::getUrl('index'))
        ->assertSee('V3')
        ->assertSee('No traffic');

    // Read from the rows: Live is also a game's own status badge.
    $versions = collect(Livewire::test(ListGames::class)->viewData('rows'))->pluck('version', 'id');

    expect($versions[$quiet->game_id])->toMatchArray(['label' => 'V3', 'state' => ['label' => 'No traffic', 'tone' => 'outline', 'dot' => false]])
        ->and($versions[$live->game_id])->toMatchArray(['label' => 'V1', 'state' => ['label' => 'Live', 'tone' => 'success', 'dot' => true]]);
});

it('narrows by status, keeping archived games out unless asked for', function () {
    Game::factory()->create(['title' => 'Visible draft']);
    Game::factory()->status(GameStatus::Archived)->create(['title' => 'Old archived']);

    Livewire::test(ListGames::class)
        ->assertSee('Visible draft')
        ->assertDontSee('Old archived')
        ->set('status', 'archived')
        ->assertSee('Old archived')
        ->assertDontSee('Visible draft')
        ->set('status', 'nonsense')
        ->assertSet('status', '');
});

it('opens a game’s editor, preview, analytics and versions from its row menu, and moves no status there', function () {
    $game = Game::factory()->create(['title' => 'Menu game']);

    $page = $this->get(GameResource::getUrl('index'))->assertOk();

    foreach (['edit', 'preview', 'analytics', 'versions'] as $tab) {
        $page->assertSee('href="'.GameResource::getUrl($tab, ['record' => $game]).'"', false);
    }

    // A game's status is changed in its editor's header band, asked first there.
    $page->assertDontSee('askStatus', false);
    expect(method_exists(ListGames::class, 'askStatus'))->toBeFalse();
});

it('lets nobody but an administrator who may manage games in', function () {
    $this->actingAs(User::factory()->moderator()->create())
        ->get(GameResource::getUrl('index'))
        ->assertForbidden();
});

it('shows each game’s plays, completion and sign-ups of the last 30 days, and a dash for none', function () {
    $draft = publishableGameDraft(1);
    liveGame($draft);
    $quiet = Game::factory()->create(['title' => 'Nobody played this']);
    $cookie = (string) config('games.visitor_cookie.name');
    // Two guests play: nobody is signed in.
    auth()->logout();

    Livewire::withCookie($cookie, str_repeat('a', 40))->test(PlayGame::class, ['slug' => $draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next');
    session()->flush();
    Livewire::withCookie($cookie, str_repeat('b', 40))->test(PlayGame::class, ['slug' => $draft['game']->slug])
        ->call('init')->call('continue');
    session()->flush();
    $this->actingAs($draft['admin']);

    $this->get(GameResource::getUrl('index'))
        ->assertOk()
        ->assertSeeInOrder([$quiet->title, '—', '—', '—']);

    // Two started, one completed, no account made from two anonymous visitors —
    // read from the rows, as any digit of the row's markup would match a bare 2.
    $rows = collect(Livewire::test(ListGames::class)->viewData('rows'))->keyBy('id');

    expect($rows[$draft['game']->id])->toMatchArray(['plays' => '2', 'completion' => '50.0%', 'signups' => '0.0%'])
        ->and($rows[$quiet->id])->toMatchArray(['plays' => null, 'completion' => null, 'signups' => null]);
});
