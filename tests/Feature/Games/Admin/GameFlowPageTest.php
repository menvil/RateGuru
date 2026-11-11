<?php

use App\Actions\Games\SaveGameFlowAction;
use App\Data\Games\GameFlowDraft;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\EditGameFlow;
use App\Livewire\Games\PreviewGame;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\ProjectSettings;
use App\Models\User;
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

it('keeps the step on screen when a step above it is removed', function () {
    $page = Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->call('insertStep', 'share')
        ->call('selectStep', 2);
    $shown = $page->get('steps.2.key');

    $page->call('removeStep', 0);

    expect($page->get('selected'))->toBe(1)
        ->and($page->get('steps.1.key'))->toBe($shown);
});

it('restores the archived flow on screen keeping what is being edited, which then saves', function () {
    $this->draft['flow']->forceFill(['archived_at' => now()])->save();
    GameFlow::factory()->create(['game_id' => $this->draft['game']->id, 'position' => 1]);

    $page = Livewire::withQueryParams(['flow' => $this->draft['flow']->id])->test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->set('steps.0.texts.title', 'Edited while archived')
        ->call('restoreFlow')
        ->assertDispatched('rg-admin-toast', message: 'Flow restored', tone: 'success')
        ->assertSet('steps.0.texts.title', 'Edited while archived');

    expect($this->draft['flow']->fresh()->archived_at)->toBeNull();

    $page->call('save')->assertDispatched('rg-admin-toast', message: 'Flow saved', tone: 'success');

    expect(GameFlowStep::query()->where('title', 'Edited while archived')->exists())->toBeTrue();
});

it('renames the flow at the version on screen, and refuses a rename over another administrator\'s save', function () {
    $flow = $this->draft['flow'];
    $page = Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->set('flowName', 'Renamed')
        ->call('renameFlow')
        ->assertDispatched('rg-admin-toast', message: 'Flow renamed', tone: 'success')
        ->set('steps.0.texts.title', 'Mine');

    $theirs = GameFlowDraft::of($flow->fresh()->load('steps'))->steps;
    $theirs[0]['texts']['title'] = 'Theirs';
    app(SaveGameFlowAction::class)->handle(User::factory()->admin()->create(), $flow->fresh(), new GameFlowDraft($theirs), $flow->fresh()->lock_version);

    $page->set('flowName', 'Renamed again')
        ->call('renameFlow')
        ->assertSet('stale', true)
        ->call('save')
        ->assertDispatched('rg-admin-toast', tone: 'error');

    expect($flow->fresh()->name)->toBe('Renamed')
        ->and(GameFlowStep::query()->where('title', 'Theirs')->exists())->toBeTrue()
        ->and(GameFlowStep::query()->where('title', 'Mine')->exists())->toBeFalse();
});

it('asks before the page is left with unsaved changes, unless it goes away on purpose', function () {
    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->assertSeeHtml('x-on:beforeunload.window="if (! $store.adminEditor.leaving && rgAdminEditorListChanges(');
});

it('takes back the page\'s leaving when an archive is refused, so what is unsaved asks again', function () {
    $page = Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->call('askFlow', 'archive')
        ->call('confirmFlow')
        ->assertDispatched('rg-admin-toast', message: 'A game keeps at least one flow. Add another before archiving this one.', tone: 'error')
        ->assertNoRedirect();

    expect(array_column($page->effects['xjs'] ?? [], 'expression'))->toContain('$store.adminEditor.leaving = false')
        ->and($this->draft['flow']->fresh()->isArchived())->toBeFalse();
});

it('marks the archived flow on screen as current in the flow menu', function () {
    GameFlow::factory()->create(['game_id' => $this->draft['game']->id, 'position' => 1]);
    $this->draft['flow']->forceFill(['archived_at' => now()])->save();

    $url = GameResource::getUrl('flow', ['record' => $this->draft['game'], 'flow' => $this->draft['flow']->id]);
    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->toMatch('#<a href="'.preg_quote(e($url), '#').'" class="rg-admin-games__set-item rg-admin-games__set-item--current"\s+aria-current="true"\s*>#');
});

it('names the + after the last step for where it adds one', function () {
    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->assertSeeHtml('aria-label="Between steps 2 and 3"')
        ->assertSeeHtml('aria-label="After step 3"')
        ->assertDontSeeHtml('aria-label="Between steps 3 and 4"');
});
