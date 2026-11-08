<?php

use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\EditGameContent;
use App\Models\GameContentSet;
use App\Models\GameRound;
use App\Models\ProjectSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(2);
    $this->actingAs($this->draft['admin']);
});

it('draws the set\'s rounds, the round on screen and the crowd pick', function () {
    $this->get(GameResource::getUrl('content', ['record' => $this->draft['game']]))
        ->assertOk()
        ->assertSee('Round 1')
        ->assertSee('Crowd pick')
        ->assertSee('90.0% upvotes')
        ->assertSee('Players score when they pick option A.')
        ->assertSee('2 of 2 rounds ready to publish')
        ->assertSee('Save content');
});

it('adds, duplicates, moves and removes rounds in the draft, and saves them', function () {
    $page = Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->call('addRound')
        ->assertSet('selected', 2)
        ->set('rounds.2.question', 'A third one?')
        ->call('duplicateRound', 0)
        ->call('moveRound', 1, -1)
        ->call('removeRound', 3);

    expect(GameRound::query()->count())->toBe(2);

    $page->call('save')->assertDispatched('rg-admin-toast', message: 'Content saved', tone: 'success');

    expect(GameRound::query()->where('content_set_id', $this->draft['set']->id)->count())->toBe(3);
});

it('says what keeps a round from being published as it is edited', function () {
    Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->call('addRound')
        ->assertSee('Question missing')
        ->assertSee('Option A: choose a post')
        ->set('rounds.2.question', 'Which?')
        ->assertDontSee('Question missing');
});

it('chooses a post for an option from the picker, never the other option\'s', function () {
    $other = $this->draft['set']->rounds()->first()->options()->where('position', 1)->first()->post_id;
    $candidate = gamePost(300, 30);

    $page = Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->call('openPicker', 0, 0)
        ->assertSee('Choose a post for option a')
        ->assertSee('#'.$candidate->id)
        ->call('pickPost', $candidate->id);

    expect($page->get('rounds.0.options.0.post_id'))->toBe($candidate->id)
        ->and($page->get('pickerFor'))->toBeNull();

    $page->call('openPicker', 0, 1)->assertDontSee('picker-post-'.$candidate->id.'"', false);
    $page->call('pickPost', $other)->assertSet('rounds.0.options.1.post_id', $other);
});

it('turns a round with an upload into one decided by its correct answer', function () {
    $key = 'r'.$this->draft['set']->rounds()->first()->id;

    Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->set("uploads.round:{$key}:1", UploadedFile::fake()->image('b.jpg'))
        ->assertSet('rounds.0.options.1.source', 'upload')
        ->assertSee('Correct answer not set')
        ->call('markCorrect', 0, 1)
        ->assertSet('rounds.0.options.1.is_correct', true)
        ->assertDontSee('Correct answer not set');
});

it('suggests profiles for a set with none and fits them to the scored rounds', function () {
    $this->draft['set']->profiles()->delete();

    $page = Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->call('showView', 'results')
        ->assertSee('No result profiles yet')
        ->call('addSuggestedProfiles');

    expect(collect($page->get('profiles'))->pluck('name')->all())->toBe(['Wild Card', 'Independent', 'Oracle']);

    $page->call('addRound')->call('showView', 'results')->call('fitRanges');

    expect(collect($page->get('profiles'))->map(fn ($p) => [$p['min'], $p['max']])->all())->toBe([[0, 1], [2, 2], [3, 3]]);
});

it('discards what is unsaved', function () {
    Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->set('rounds.0.question', 'Changed')
        ->call('discard')
        ->assertSet('rounds.0.question', 'Which one would you rather see in your feed?');
});

it('keys a save\'s errors to the field they belong to', function () {
    Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->set('rounds.0.question', str_repeat('a', 121))
        ->call('save')
        ->assertHasErrors(['rounds.0.question']);
});

it('makes a new set and opens it', function () {
    $page = Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->set('newSetName', 'Quick 3')
        ->call('createSet', false);

    $set = GameContentSet::query()->where('name', 'Quick 3')->sole();
    $page->assertRedirect(GameResource::getUrl('content', ['record' => $this->draft['game'], 'set' => $set->id]));
});
