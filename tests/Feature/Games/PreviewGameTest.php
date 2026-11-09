<?php

use App\Filament\Resources\Games\GameResource;
use App\Livewire\Games\PreviewGame;
use App\Models\GameAnswer;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Models\GameRound;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(2);
    $this->actingAs($this->draft['admin']);
});

it('previews a draft for an administrator only, whatever the games switch says', function () {
    $url = route('games.preview', ['game' => $this->draft['game']->id]);

    $this->get($url)->assertOk()->assertSee('Preview — draft — nothing is recorded');

    $this->actingAs(User::factory()->moderator()->create())->get($url)->assertForbidden();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
});

it('plays a draft through to its result and records nothing', function () {
    Livewire::withQueryParams(['set' => $this->draft['set']->id, 'flow' => $this->draft['flow']->id])
        ->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->call('continue')->assertSee('Pair 1 of 2')
        ->call('choose', 0)->assertSee('You’re with the crowd.')
        ->call('next')->call('choose', 0)->call('next')
        ->assertSee('Oracle')
        ->assertSee('You matched the crowd in 2 of 2 picks');

    expect(GameAttempt::query()->count() + GameAnswer::query()->count() + GameAssignment::query()->count() + GameEvent::query()->count() + GameEmailCapture::query()->count())->toBe(0);
});

it('starts from a round, or straight at the result for a number of matches', function () {
    Livewire::withQueryParams(['start' => 'round:1'])->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSee('Pair 2 of 2');

    Livewire::withQueryParams(['start' => 'result:1'])->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSee('You matched the crowd in 1 of 2 picks')
        ->assertSee('Independent');
});

it('previews a draft that is not publishable yet', function () {
    GameRound::query()->first()->forceFill(['question' => null])->save();
    $this->draft['set']->profiles()->delete();

    Livewire::withQueryParams(['start' => 'round:0'])->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->call('choose', 0)
        ->assertSee('You’re with the crowd.');
});

it('previews a published version as it was published', function () {
    $version = liveGame($this->draft);
    GameRound::query()->first()->forceFill(['question' => 'Changed in the draft'])->save();

    Livewire::withQueryParams(['version' => $version->id, 'start' => 'round:0'])->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSee('Which one would you rather see in your feed?')
        ->assertDontSee('Changed in the draft');
});

it('draws the preview frame in the editor', function () {
    $this->get(GameResource::getUrl('preview', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSee('Preview only. Picks, emails and accounts aren’t recorded.')
        ->assertSee('data-testid="game-preview-frame"', false)
        ->assertSee(route('games.preview', ['game' => $this->draft['game']->id]), false);
});
