<?php

use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\PreviewGame;
use App\Models\GameContentSet;
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
    $page = Livewire::withQueryParams(['visitor' => 'authenticated'])->test(PreviewGame::class, ['record' => $this->draft['game']->id])
        ->assertSet('visitor', 'authenticated');
    parse_str((string) parse_url((string) $page->viewData('frameUrl'), PHP_URL_QUERY), $query);

    expect($query['visitor'])->toBe('authenticated');

    // A visitor the preview no longer offers falls back to an anonymous one.
    $page = Livewire::withQueryParams(['visitor' => 'known'])->test(PreviewGame::class, ['record' => $this->draft['game']->id])
        ->assertSet('visitor', 'anonymous')
        ->assertSeeInOrder(['Visitor', 'Anonymous', 'Logged in'])
        ->assertDontSee('Email known');
    parse_str((string) parse_url((string) $page->viewData('frameUrl'), PHP_URL_QUERY), $query);

    expect($query['visitor'])->toBe('anonymous');
});

it('plays this game\'s first set and flow when the address names another game\'s, or one gone', function () {
    $foreign = publishableGameDraft(1);
    $gone = GameContentSet::factory()->create(['game_id' => $this->draft['game']->id]);
    $goneId = $gone->id;
    $gone->delete();

    foreach ([['set' => $foreign['set']->id, 'flow' => $foreign['flow']->id], ['set' => $goneId, 'flow' => 999_999]] as $address) {
        $page = Livewire::withQueryParams($address)->test(PreviewGame::class, ['record' => $this->draft['game']->id])
            ->assertSet('set', $this->draft['set']->id)
            ->assertSet('flow', $this->draft['flow']->id);
        parse_str((string) parse_url((string) $page->viewData('frameUrl'), PHP_URL_QUERY), $query);

        expect($query)->toMatchArray(['set' => (string) $this->draft['set']->id, 'flow' => (string) $this->draft['flow']->id])
            ->and(array_keys($page->viewData('results')))->toBe(['result:0', 'result:1', 'result:2']);
    }

    // Set by the browser afterwards, the same.
    $page = Livewire::test(PreviewGame::class, ['record' => $this->draft['game']->id])->set('set', $foreign['set']->id);
    parse_str((string) parse_url((string) $page->viewData('frameUrl'), PHP_URL_QUERY), $query);

    expect($query['set'])->toBe((string) $this->draft['set']->id);
});

it('marks the step on screen in Journey for assistive technology too', function () {
    Livewire::test(PreviewGame::class, ['record' => $this->draft['game']->id])
        ->assertSeeHtml('x-bind:aria-current="step === ');
});

it('opens for an administrator only', function () {
    $url = GameResource::getUrl('preview', ['record' => $this->draft['game']]);

    $this->get($url)->assertOk();
    $this->actingAs(User::factory()->moderator()->create())->get($url)->assertForbidden();
});
