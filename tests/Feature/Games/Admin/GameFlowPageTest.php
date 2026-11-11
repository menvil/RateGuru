<?php

use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\EditGameFlow;
use App\Livewire\Games\PreviewGame;
use App\Models\GameFlowStep;
use App\Models\ProjectSettings;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
    $this->actingAs($this->draft['admin']);
});

it('draws the flow in its band, the library, its steps in order and the selected step\'s settings', function () {
    $this->get(GameResource::getUrl('flow', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSeeInOrder(['Flow', $this->draft['flow']->name, 'Not in a version yet', 'Copy flow', 'Preview'])
        ->assertSeeInOrder(['Steps', 'Landing', 'Intro', 'Game'])
        ->assertSeeInOrder(['Landing', 'Game', 'Same in every flow', 'Full Result'])
        ->assertSee('Step 1 of 3')
        ->assertSee('Hero title')
        ->assertSee('Save flow');
});

it('says who sees a step whose audience can be chosen', function () {
    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->call('insertStep', 'share')
        ->assertSee('Everyone sees this step.')
        ->set('steps.3.audience', 'anonymous')
        ->assertSee('Logged-in visitors skip this step.');
});

it('names the versions a flow was used in', function () {
    liveGame($this->draft);

    $this->get(GameResource::getUrl('flow', ['record' => $this->draft['game']]))
        ->assertSee('Used in V1');
});

it('plays the selected step in the step preview as the flow stands, unsaved edits included', function () {
    $page = Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->set('steps.0.texts.title', 'Before saving?')
        ->set('panel', 'preview')
        ->assertSee('Preview as');

    parse_str((string) parse_url((string) $page->viewData('stepPreviewUrl'), PHP_URL_QUERY), $query);

    expect($query)->toMatchArray(['start' => 'step:'.$this->draft['flow']->steps()->orderBy('position')->first()->key(), 'framed' => '1', 'visitor' => 'anonymous']);

    Livewire::withQueryParams($query)->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSee('Before saving?');

    expect(GameFlowStep::query()->where('title', 'Before saving?')->exists())->toBeFalse();
});

it('previews a step as an anonymous visitor or a logged-in player, light or dark', function () {
    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->call('insertStep', 'email_capture')
        ->set('panel', 'preview')
        ->assertSeeInOrder(['Preview as', 'Anonymous', 'Logged in', 'Theme', 'Dark', 'Light'])
        ->assertDontSee('Email known')
        ->assertDontSee('skip this step')
        ->set('previewVisitor', 'authenticated')
        ->assertSee('Logged-in players skip this step');
});

it('moves the delay before the next pair by half a second, within its limits', function () {
    $page = Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->call('selectStep', 1);
    $start = (float) $page->get('steps.1.settings.advance_seconds');

    $page->call('nudgeDelay', 1)->assertSet('steps.1.settings.advance_seconds', $start + 0.5);

    foreach (range(1, 20) as $ignored) {
        $page->call('nudgeDelay', 1);
    }

    $page->assertSet('steps.1.settings.advance_seconds', 8.0);
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
