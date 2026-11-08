<?php

use App\Enums\GameStatus;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\ListGames;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
});

it('lists the games with New game in the top bar', function () {
    Game::factory()->create(['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']);

    $this->get(GameResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Create and run interactive games')
        ->assertSee('Trust Your Taste')
        ->assertSee('/games/trust-your-taste')
        ->assertSee('New game')
        ->assertSee('Not published yet');
});

it('says when there are no games yet, and how to start one', function () {
    $this->get(GameResource::getUrl('index'))
        ->assertOk()
        ->assertSee('No games yet');
});

it('shows a game\'s newest version and whether it has traffic', function () {
    $version = GameVersion::factory()->create(['number' => 3]);

    $this->get(GameResource::getUrl('index'))
        ->assertSee('V3')
        ->assertSee('No traffic');
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

it('archives a game from its row, asked first', function () {
    $game = Game::factory()->create(['title' => 'To archive']);

    Livewire::test(ListGames::class)
        ->call('askStatus', $game->id, 'archived')
        ->assertSee('Archive this game?')
        ->call('changeStatus')
        ->assertDispatched('rg-admin-toast', message: 'To archive: Archived', tone: 'success');

    expect($game->fresh()->status)->toBe(GameStatus::Archived);
});

it('says why a game cannot go live yet instead of making it live', function () {
    $game = Game::factory()->create();

    Livewire::test(ListGames::class)
        ->call('askStatus', $game->id, 'live')
        ->call('changeStatus')
        ->assertDispatched('rg-admin-toast', message: 'Publish a version and give it a share in Traffic first.', tone: 'error');

    expect($game->fresh()->status)->toBe(GameStatus::Draft);
});

it('lets nobody but an administrator who may manage games in', function () {
    $this->actingAs(User::factory()->moderator()->create())
        ->get(GameResource::getUrl('index'))
        ->assertForbidden();
});
