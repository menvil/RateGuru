<?php

use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\PreviewGame;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(2);
    $this->actingAs($this->draft['admin']);
});

it('plays the game framed, in an iPhone, a browser window or across the page', function () {
    $game = $this->draft['game'];
    $page = Livewire::test(PreviewGame::class, ['record' => $game->id])
        ->assertSee('Preview mode')
        ->assertSee('rg-admin-games__phone', false);

    parse_str((string) parse_url((string) $page->viewData('frameUrl'), PHP_URL_QUERY), $query);

    expect($query)->toMatchArray(['framed' => '1', 'set' => (string) $this->draft['set']->id, 'flow' => (string) $this->draft['flow']->id]);

    $page->set('device', 'desktop')
        ->assertSee('rg-admin-games__browser', false)
        ->assertSee('/games/'.$game->slug);

    $page->set('device', 'page')
        ->assertSee('rg-admin-games__page-frame', false)
        ->assertDontSee('rg-admin-games__browser-window', false);
});

it('lists the flow\'s steps in Journey, and starts the game again at the one chosen', function () {
    $steps = $this->draft['flow']->steps()->get();
    $page = Livewire::test(PreviewGame::class, ['record' => $this->draft['game']->id])
        ->assertSeeInOrder(['Journey', 'Landing', 'Game', 'Full Result', 'Jump to the result for']);

    $page->set('start', 'step:'.$steps->last()->key());
    parse_str((string) parse_url((string) $page->viewData('frameUrl'), PHP_URL_QUERY), $query);

    expect($query['start'])->toBe('step:'.$steps->last()->key());
});

it('previews as an anonymous visitor or a logged-in player', function () {
    Livewire::withQueryParams(['visitor' => 'known'])->test(PreviewGame::class, ['record' => $this->draft['game']->id])
        ->assertSet('visitor', 'anonymous')
        ->assertSeeInOrder(['Visitor', 'Anonymous', 'Logged in'])
        ->assertDontSee('Email known');
});

it('opens for an administrator only', function () {
    $url = GameResource::getUrl('preview', ['record' => $this->draft['game']]);

    $this->get($url)->assertOk();
    $this->actingAs(User::factory()->moderator()->create())->get($url)->assertForbidden();
});
