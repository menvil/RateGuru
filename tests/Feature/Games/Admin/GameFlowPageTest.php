<?php

use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\EditGameFlow;
use App\Models\GameFlowStep;
use App\Models\ProjectSettings;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
    $this->actingAs($this->draft['admin']);
});

it('draws the flow\'s steps, the library and the selected step\'s settings', function () {
    $this->get(GameResource::getUrl('flow', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSee('1. Landing')
        ->assertSee('2. Game')
        ->assertSee('3. Full Result')
        ->assertSee('Hero title')
        ->assertSee('Save flow');
});

it('offers between two steps only the steps that fit there, and adds one', function () {
    $page = Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->call('openInsert', 3)
        ->assertSee('Steps that fit here')
        ->call('insertStep', 'share')
        ->assertSet('selected', 3);

    expect(collect($page->get('steps'))->pluck('type')->all())->toBe(['landing', 'game', 'full_result', 'share']);

    $page->call('insertStep', 'intro', 3)->assertDispatched('rg-admin-toast', message: 'That step doesn’t fit there.', tone: 'error');
});

it('lists the flow\'s problems as it stands, and keeps the game', function () {
    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->call('removeStep', 2)
        ->assertSee('Missing Full Result.')
        ->call('removeStep', 1)
        ->assertSet('steps.1.type', 'game');
});

it('saves the flow from the top bar', function () {
    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->set('steps.0.texts.title', 'Pick one')
        ->call('moveStep', 0, 1)
        ->call('save')
        ->assertDispatched('rg-admin-toast', message: 'Flow saved', tone: 'success');

    expect(GameFlowStep::query()->where('flow_id', $this->draft['flow']->id)->orderBy('position')->first()->type->value)->toBe('game')
        ->and(GameFlowStep::query()->where('type', 'landing')->sole()->title)->toBe('Pick one');
});
