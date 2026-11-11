<?php

use App\Enums\PostStatus;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\EditGameContent;
use App\Livewire\Games\PreviewGame;
use App\Models\GameContentSet;
use App\Models\GameRound;
use App\Models\GameRoundOption;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;
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

it('plays the round on screen in its live preview as it stands, unsaved edits included', function () {
    $page = Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->assertSee('Live preview')
        ->set('rounds.0.question', 'Which one, before saving?');

    parse_str((string) parse_url((string) $page->viewData('liveUrl'), PHP_URL_QUERY), $query);

    expect($query)->toMatchArray(['set' => (string) $this->draft['set']->id, 'start' => 'round:0', 'framed' => '1']);

    // Inside the phone frame the page leaves out its own preview banner.
    Livewire::withQueryParams($query)->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSee('Pair 1 of 2')
        ->assertSee('Which one, before saving?')
        ->assertDontSee('nothing is recorded');

    expect(GameRound::query()->where('question', 'Which one, before saving?')->exists())->toBeFalse();
});

it('frames every pair of the live preview in the shape Photos asks for', function () {
    $page = Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->assertSee('As uploaded');

    parse_str((string) parse_url((string) $page->viewData('liveUrl'), PHP_URL_QUERY), $query);
    expect($query)->not->toHaveKey('shape');

    $page->set('previewShape', 'portrait');
    parse_str((string) parse_url((string) $page->viewData('liveUrl'), PHP_URL_QUERY), $query);
    expect($query['shape'])->toBe('portrait');
});

it('previews a round only the draft has, from where it stands', function () {
    $page = Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->call('addRound')
        ->set('rounds.2.question', 'A round not saved yet?');

    parse_str((string) parse_url((string) $page->viewData('liveUrl'), PHP_URL_QUERY), $query);

    Livewire::withQueryParams($query)->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSee('Pair 3 of 3')
        ->assertSee('A round not saved yet?');
});

it('shows an unsaved draft to the administrator who handed it over only', function () {
    $saved = $this->draft['set']->rounds()->first()->question;
    $page = Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->set('rounds.0.question', 'Only mine to see?');

    parse_str((string) parse_url((string) $page->viewData('liveUrl'), PHP_URL_QUERY), $query);

    $this->actingAs(User::factory()->admin()->create());

    Livewire::withQueryParams($query)->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertDontSee('Only mine to see?')
        ->assertSee($saved);
});

it('names the option a replacement changes: the post short of votes, the one off the site, or either when the shares are closer than the set\'s gap', function () {
    $this->draft['set']->forceFill(['crowd_min_gap' => 5])->save();
    $options = $this->draft['set']->rounds()->orderBy('position')->first()->options()->orderBy('position')->get();
    $page = fn () => Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id]);

    Post::query()->whereKey($options[1]->post_id)->update(['upvotes_count' => 30, 'downvotes_count' => 10]);
    $page()->assertSee('Option B’s post has 40 site votes; each post needs at least 100')
        ->assertSee('Choose another post for B')
        ->assertDontSee('Choose another post for A')
        ->assertSee('Use a correct answer');

    Post::query()->whereKey($options[1]->post_id)->update(['upvotes_count' => 895, 'downvotes_count' => 105]);
    $page()->assertSee('too close to call')
        ->assertSee('Choose another post for A')
        ->assertSee('Choose another post for B');

    Post::query()->whereKey($options[0]->post_id)->update(['status' => PostStatus::Hidden]);
    $page()->assertSee('Option A’s post is no longer on the site.')
        ->assertSee('Choose another post for A')
        ->assertDontSee('Choose another post for B')
        ->assertDontSee('Use a correct answer');
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

it('shows a picture chosen for an option in the live preview before it is saved', function () {
    $key = 'r'.$this->draft['set']->rounds()->orderBy('position')->first()->id;
    $page = Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->set("uploads.round:{$key}:1", UploadedFile::fake()->image('beach.jpg', 900, 1200));

    $upload = $page->get('uploads')["round:{$key}:1"];
    parse_str((string) parse_url((string) $page->viewData('liveUrl'), PHP_URL_QUERY), $query);

    Livewire::withQueryParams($query)->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSee('data-testid="game-option-1"', false)
        ->assertSee('preview-file/'.$upload->getFilename(), false);

    expect(GameRoundOption::query()->whereNotNull('asset_id')->exists())->toBeFalse();
});

it('drops a picture chosen but not stored when the draft is discarded, so a later save stores nothing of it', function () {
    $key = 'r'.$this->draft['set']->rounds()->orderBy('position')->first()->id;

    $page = Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->set("uploads.round:{$key}:1", UploadedFile::fake()->image('beach.jpg'))
        ->call('discard')
        ->assertSet('uploads', [])
        ->assertSet('rounds.0.options.1.source', 'post');

    $page->call('save');

    expect(GameRoundOption::query()->whereNotNull('asset_id')->exists())->toBeFalse();
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

it('makes a new empty set, or a copy of this one, and opens it', function () {
    $game = $this->draft['game'];
    $this->draft['set']->update(['name' => 'Standard']);

    Livewire::test(EditGameContent::class, ['record' => $game->id])
        ->call('createSet', false)
        ->assertRedirect(GameResource::getUrl('content', ['record' => $game, 'set' => GameContentSet::query()->where('name', 'New set')->sole()->id]));

    Livewire::test(EditGameContent::class, ['record' => $game->id, 'set' => $this->draft['set']->id])
        ->call('createSet', true);

    $copy = GameContentSet::query()->where('name', 'Standard copy')->sole();

    expect($copy->rounds()->count())->toBe(2);
});

it('names each set\'s rounds and versions in the set menu, and says what the set on screen may become', function () {
    $game = $this->draft['game'];
    $this->draft['set']->update(['name' => 'Standard']);

    $this->get(GameResource::getUrl('content', ['record' => $game]))
        ->assertOk()
        ->assertSeeInOrder(['Content set', 'Standard', '2 rounds · Not in any version'])
        ->assertSee('This is the only set. Add another before deleting or archiving it.')
        ->assertDontSee('Delete set')
        ->assertSee('Results belong to this set: profiles and score ranges for its rounds.')
        ->assertSee('Drag rows to reorder, or use Move up and Move down.');

    liveGame($this->draft);
    GameContentSet::factory()->create(['game_id' => $game->id, 'name' => 'Quick 3']);

    $this->get(GameResource::getUrl('content', ['record' => $game, 'set' => $this->draft['set']->id]))
        ->assertSee('2 rounds · Used in V1')
        ->assertSee('0 rounds · Not in any version')
        ->assertSee('Used in V1, so it can’t be deleted.', false)
        ->assertSee('Archive set')
        ->assertDontSee('Delete set');
});

it('restores an archived set from the set menu and opens it', function () {
    $game = $this->draft['game'];
    $archived = GameContentSet::factory()->create(['game_id' => $game->id, 'name' => 'Old set', 'archived_at' => now()]);

    $this->get(GameResource::getUrl('content', ['record' => $game, 'set' => $this->draft['set']->id]))
        ->assertSee('Show archived · 1');

    Livewire::test(EditGameContent::class, ['record' => $game->id, 'set' => $this->draft['set']->id])
        ->call('restoreSet', $archived->id)
        ->assertRedirect(GameResource::getUrl('content', ['record' => $game, 'set' => $archived->id]));

    expect($archived->fresh()->archived_at)->toBeNull();
});

it('moves a dragged round into the place of the round it is dropped on, keeping the round on screen', function () {
    $page = Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->call('addRound')
        ->set('rounds.2.internal_name', 'Third')
        ->call('selectRound', 1);

    $page->call('dropRound', 2, 0);

    expect(array_column($page->get('rounds'), 'internal_name'))->toBe(['Third', 'Round 1', 'Round 2'])
        ->and($page->get('selected'))->toBe(2);

    $page->call('moveRound', 0, 1);

    expect(array_column($page->get('rounds'), 'internal_name'))->toBe(['Round 1', 'Third', 'Round 2']);
});
