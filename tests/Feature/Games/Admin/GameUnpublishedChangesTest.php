<?php

use App\Enums\GameFlowGoal;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\EditGameContent;
use App\Filament\Resources\Games\Pages\EditGameFlow;
use App\Filament\Resources\Games\Pages\EditGameGeneral;
use App\Filament\Resources\Games\Pages\GameVersions;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\GameRound;
use App\Models\MediaAsset;
use App\Models\ProjectSettings;
use App\Queries\Games\GameUnpublishedChangesQuery;
use App\Support\Games\Publishing\VersionChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Symfony\Component\Finder\SplFileInfo;

/*
 * Saving changes the draft, never what the players play: a tab whose saved
 * changes they don't see names them, says which version they play instead,
 * and opens Versions set to publish one that has them in its place. What
 * counts is what a version published now would change for players, never
 * when something was saved.
 */

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(2);
    $this->actingAs($this->draft['admin']);
});

function publishUrlFor(array $draft): string
{
    return e(GameResource::getUrl('versions', ['record' => $draft['game'], 'set' => $draft['set']->id, 'flow' => $draft['flow']->id, 'take_over' => $draft['game']->versions()->sole()->id]));
}

it('says on Content when a round was saved after the version the players play', function () {
    liveGame($this->draft);
    $page = fn () => Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id]);

    $page()->assertDontSee('data-testid="game-unpublished"', false);

    $this->travel(1)->minutes();
    GameRound::query()->where('content_set_id', $this->draft['set']->id)->first()->update(['question' => 'Which one, now?']);

    $page()->assertSee('Players don’t see these saved changes yet.')
        ->assertSee('1 change since V1:')
        ->assertSee('They play V1, published before them. Publish V2 to put them live')
        ->assertSee('href="'.publishUrlFor($this->draft).'"', false);
});

it('says on Flow when a step was saved after the version the players play', function () {
    liveGame($this->draft);
    $page = fn () => Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id]);

    $page()->assertDontSee('data-testid="game-unpublished"', false);

    $this->travel(1)->minutes();
    GameFlowStep::query()->where('flow_id', $this->draft['flow']->id)->where('type', 'full_result')->sole()->update(['title' => 'Join us']);

    $page()->assertSee('1 change since V1:')
        ->assertSee('Full Result · Sign-up title')
        ->assertSee('They play V1, published before them.')
        ->assertSee('href="'.publishUrlFor($this->draft).'"', false);
});

it('says on General when the title, a translation of it, the description or the cover differ from what the players play', function (Closure $change, string $named) {
    liveGame($this->draft);
    $page = fn () => Livewire::test(EditGameGeneral::class, ['record' => $this->draft['game']->id]);

    // Saved again unchanged, or its status moved: nothing the players miss.
    $this->travel(1)->minutes();
    $this->draft['game']->fresh()->touch();
    $page()->assertDontSee('data-testid="game-unpublished"', false);

    $change($this->draft['game']->fresh());

    $page()->assertSee('They play V1, published before them.')
        ->assertSee($named);
})->with([
    'the title' => [fn ($game) => $game->forceFill(['title' => 'Trust Your Gut'])->save(), 'Public title'],
    'a translation of the title' => [fn ($game) => $game->forceFill(['title_translations' => ['de' => 'Vertrau deinem Geschmack']])->save(), 'Public title · translations'],
    'the description' => [fn ($game) => $game->forceFill(['description' => 'Something else'])->save(), 'Description'],
    'the cover' => [fn ($game) => $game->forceFill(['cover_asset_id' => MediaAsset::factory()->create()->id])->save(), 'Cover image'],
]);

it('says nothing while no version is in traffic, or of a set no version in traffic was made from', function () {
    $this->travel(1)->minutes();
    GameRound::query()->where('content_set_id', $this->draft['set']->id)->first()->update(['question' => 'Which one, now?']);

    Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->assertDontSee('data-testid="game-unpublished"', false);

    liveGame($this->draft);
    $other = GameContentSet::factory()->create(['game_id' => $this->draft['game']->id, 'name' => 'Another']);
    $this->travel(1)->minutes();
    $other->touch();

    Livewire::withQueryParams(['set' => $other->id])->test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->assertDontSee('data-testid="game-unpublished"', false);
});

it('opens Versions set to publish from the set and flow the address names, taking over the version it names', function () {
    $version = liveGame($this->draft);
    $flow = GameFlow::factory()->create(['game_id' => $this->draft['game']->id, 'position' => 1]);

    Livewire::withQueryParams(['flow' => $flow->id])->test(GameVersions::class, ['record' => $this->draft['game']->id])
        ->assertSet('publishFlowId', $flow->id)
        ->assertSet('takeOver', '');

    Livewire::withQueryParams(['set' => $this->draft['set']->id, 'flow' => $this->draft['flow']->id, 'take_over' => $version->id])
        ->test(GameVersions::class, ['record' => $this->draft['game']->id])
        ->assertSet('publishSetId', $this->draft['set']->id)
        ->assertSet('publishFlowId', $this->draft['flow']->id)
        ->assertSet('takeOver', (string) $version->id);

    // Another game's, though in traffic there: the first of each, taking over nothing.
    $foreign = publishableGameDraft(1);

    Livewire::withQueryParams(['set' => $foreign['set']->id, 'flow' => $foreign['flow']->id, 'take_over' => liveGame($foreign)->id])
        ->test(GameVersions::class, ['record' => $this->draft['game']->id])
        ->assertSet('publishSetId', $this->draft['set']->id)
        ->assertSet('publishFlowId', $this->draft['flow']->id)
        ->assertSet('takeOver', '');
});

it('says nothing on Flow when the flow is renamed, kept as it was, or saved without a change', function () {
    liveGame($this->draft);
    $this->travel(1)->minutes();
    $lock = $this->draft['flow']->fresh()->lock_version;

    $page = Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        // Done with the name as it was: nothing written, nothing to put live, and the editor isn't left behind.
        ->call('renameFlow')
        ->assertNotDispatched('rg-admin-toast')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('stale', false)
        ->assertDontSee('data-testid="game-unpublished"', false);

    expect($this->draft['flow']->fresh()->lock_version)->toBe($lock);

    // A new name is the editor's alone: players see no flow's name.
    $page->set('flowName', 'Result First, renamed')->call('renameFlow');
    expect($this->draft['flow']->fresh())->name->toBe('Result First, renamed')->lock_version->toBe($lock + 1);
    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])->assertDontSee('data-testid="game-unpublished"', false);

    // A real change still says so: here, the goal the flow counts.
    $page->set('goal', 'completed')->call('save')->assertHasNoErrors();
    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])->assertSee('data-testid="game-unpublished"', false);
});

it('says nothing on Content when the set is renamed, kept as it was, or saved without a change', function () {
    liveGame($this->draft);
    $this->travel(1)->minutes();
    $lock = $this->draft['set']->fresh()->lock_version;

    $page = Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])
        ->call('renameSet')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('stale', false)
        ->assertDontSee('data-testid="game-unpublished"', false);

    expect($this->draft['set']->fresh()->lock_version)->toBe($lock);

    $page->set('setName', 'Beach and mountains, renamed')->call('renameSet');
    expect($this->draft['set']->fresh()->name)->toBe('Beach and mountains, renamed');
    Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id])->assertDontSee('data-testid="game-unpublished"', false);
});

it('names the one text that changed: Full Result’s way-on title', function () {
    $fullResult = GameFlowStep::query()->where('flow_id', $this->draft['flow']->id)->where('type', 'full_result')->sole();
    $fullResult->forceFill(['settings' => [...$fullResult->settings, 'onward' => 'post'], 'secondary_label' => 'One more'])->save();
    liveGame($this->draft);

    $this->travel(1)->minutes();
    $fullResult->fresh()->forceFill(['secondary_label' => 'Not done yet'])->save();

    expect(app(GameUnpublishedChangesQuery::class)->forFlow($this->draft['game']->fresh(), $this->draft['flow']->id)?->changes())
        ->toBe(['Full Result · Way-on title']);

    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->assertSee('1 change since V1:')
        ->assertSee('Full Result · Way-on title')
        ->assertSee('Players don’t see these saved changes yet.')
        ->assertDontSee('data-testid="game-unpublished-all"', false);
});

it('says nothing after a save that changed nothing players meet, whatever it did to save times', function (Closure $save, string $page) {
    liveGame($this->draft);
    $this->travel(1)->minutes();
    $save($this->draft);

    expect(app(GameUnpublishedChangesQuery::class)->forFlow($this->draft['game']->fresh(), $this->draft['flow']->id))->toBeNull()
        ->and(app(GameUnpublishedChangesQuery::class)->forSet($this->draft['game']->fresh(), $this->draft['set']->id))->toBeNull();

    Livewire::test($page, ['record' => $this->draft['game']->id])
        ->assertDontSee('data-testid="game-unpublished"', false);
})->with([
    'the flow and a step saved again as they were' => [function (array $draft): void {
        $draft['flow']->fresh()->touch();
        $draft['flow']->steps()->first()->touch();
    }, EditGameFlow::class],
    'the way-on title of a Full Result that goes on to the next step, which the editor hides' => [function (array $draft): void {
        $draft['flow']->steps()->where('type', 'full_result')->sole()->forceFill(['secondary_label' => 'Never shown'])->save();
    }, EditGameFlow::class],
    'a round saved again with no translations instead of none' => [function (array $draft): void {
        $draft['set']->rounds()->orderBy('position')->first()->forceFill(['question_translations' => []])->save();
    }, EditGameContent::class],
    'a post the version shows voted on, and its round touched' => [function (array $draft): void {
        $round = $draft['set']->rounds()->orderBy('position')->first();
        $round->options()->orderBy('position')->first()->post->forceFill(['upvotes_count' => 10, 'downvotes_count' => 990])->save();
        $round->touch();
    }, EditGameContent::class],
]);

it('names one or three changes, and of five the first three and how many more, with all of them behind a disclosure', function (int $edited) {
    $draft = publishableGameDraft(5);
    $this->actingAs($draft['admin']);
    liveGame($draft);
    $this->travel(1)->minutes();

    $draft['set']->rounds()->orderBy('position')->take($edited)->get()
        ->each(fn (GameRound $round) => $round->forceFill(['question' => 'Which one, round '.($round->position + 1).'?'])->save());

    $names = array_map(fn (int $round): string => 'Round '.$round.' · Question', range(1, $edited));
    $page = Livewire::test(EditGameContent::class, ['record' => $draft['game']->id])
        ->assertSee(($edited === 1 ? '1 change' : $edited.' changes').' since V1:')
        ->assertSee(implode(', ', array_slice($names, 0, 3)).($edited > 3 ? ' and '.($edited - 3).' more' : ''))
        ->assertSee('Players don’t see these saved changes yet.')
        ->assertSee('Publish V2…');

    if ($edited > 3) {
        $page->assertSee('<details class="rg-admin-games__unpublished-all" wire:ignore.self data-testid="game-unpublished-all">', false)
            ->assertSee('<summary class="rg-admin-games__unpublished-toggle">', false)
            ->assertSeeInOrder(['All 5 changes', 'Content set “'.$draft['set']->name.'”', ...$names]);
    } else {
        $page->assertDontSee('data-testid="game-unpublished-all"', false);
    }
})->with(['one' => 1, 'three' => 3, 'five' => 5]);

it('says nothing when a blank text the editor shows is stored as nothing, or the other way round', function () {
    // Going on to a post, Full Result shows its way-on texts: the notice is compared.
    $step = GameFlowStep::query()->where('flow_id', $this->draft['flow']->id)->where('type', 'full_result')->sole();
    $step->forceFill(['settings' => [...$step->settings, 'onward' => 'post'], 'notice' => ''])->save();
    liveGame($this->draft);
    $this->travel(1)->minutes();

    $step->fresh()->forceFill(['notice' => null])->save();

    expect(app(GameUnpublishedChangesQuery::class)->forFlow($this->draft['game']->fresh(), $this->draft['flow']->id))->toBeNull();
    Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id])
        ->assertDontSee('data-testid="game-unpublished"', false);
});

it('names on Flow the set’s changes too, and on Content the flow’s: Publish freezes both', function () {
    liveGame($this->draft);
    $this->travel(1)->minutes();
    $this->draft['set']->rounds()->orderBy('position')->first()->forceFill(['question' => 'Which one, now?'])->save();
    $step = GameFlowStep::query()->where('flow_id', $this->draft['flow']->id)->where('type', 'full_result')->sole();
    $step->forceFill(['title' => 'Keep going'])->save();

    $flow = app(GameUnpublishedChangesQuery::class)->forFlow($this->draft['game']->fresh(), $this->draft['flow']->id);
    $set = app(GameUnpublishedChangesQuery::class)->forSet($this->draft['game']->fresh(), $this->draft['set']->id);

    expect($flow?->changes())->toBe(['Round 1 · Question', 'Full Result · Sign-up title'])
        ->and($set?->changes())->toBe(['Round 1 · Question', 'Full Result · Sign-up title'])
        ->and(collect($flow->groups)->pluck('title')->all())->toBe(['Content set “'.$this->draft['set']->name.'”', 'Flow “'.$this->draft['flow']->name.'”']);
});

it('names a title translated in the very second the version was published', function () {
    liveGame($this->draft);
    // No save time stands between: the game's own texts are compared as they stand.
    $this->draft['game']->fresh()->forceFill(['title_translations' => ['de' => 'Neu'], 'updated_at' => $this->draft['game']->fresh()->updated_at])->save();

    expect(app(GameUnpublishedChangesQuery::class)->forGame($this->draft['game']->fresh())?->changes())->toBe(['Public title · translations']);
});

it('names a step or a round saved in the very second the version was published, and still nothing a save of that second left as it was', function () {
    $this->freezeSecond();
    $at = liveGame($this->draft)->fresh()->published_at;
    $step = GameFlowStep::query()->where('flow_id', $this->draft['flow']->id)->where('type', 'full_result')->sole();
    $round = GameRound::query()->where('content_set_id', $this->draft['set']->id)->orderBy('position')->first();
    $query = app(GameUnpublishedChangesQuery::class);

    $step->forceFill(['updated_at' => $at])->save();
    $round->forceFill(['updated_at' => $at])->save();

    expect($query->forFlow($this->draft['game']->fresh(), $this->draft['flow']->id))->toBeNull()
        ->and($query->forSet($this->draft['game']->fresh(), $this->draft['set']->id))->toBeNull();

    $step->forceFill(['title' => 'Join us', 'updated_at' => $at])->save();

    expect($query->forFlow($this->draft['game']->fresh(), $this->draft['flow']->id)?->changes())->toBe(['Full Result · Sign-up title']);

    $round->forceFill(['question' => 'Which one, now?', 'updated_at' => $at])->save();

    expect($query->forSet($this->draft['game']->fresh(), $this->draft['set']->id)?->changes())->toBe(['Round 1 · Question', 'Full Result · Sign-up title']);
});

it('names a goal changed since a version published before goals, by the goal that version counts', function () {
    $version = liveGame($this->draft);
    // Published before goals: the snapshot froze none, and the goal it counts was written on the version.
    $snapshot = $version->snapshot;
    $snapshot['schema'] = 1;
    unset($snapshot['flow']['goal']);
    $counts = fn (GameFlowGoal $goal) => DB::table('game_versions')->where('id', $version->id)->update(['snapshot' => json_encode($snapshot), 'snapshot_schema' => 1, 'goal' => $goal->value]);
    $counts(GameFlowGoal::Account);

    $this->travel(1)->minutes();
    $this->draft['flow']->fresh()->forceFill(['goal' => GameFlowGoal::Completed])->save();

    expect(app(GameUnpublishedChangesQuery::class)->forFlow($this->draft['game']->fresh(), $this->draft['flow']->id)?->changes())->toBe(['Goal']);

    $counts(GameFlowGoal::Completed);

    expect(app(GameUnpublishedChangesQuery::class)->forFlow($this->draft['game']->fresh(), $this->draft['flow']->id))->toBeNull();
});

it('lists every change on Versions too, behind a disclosure, once more are counted than named', function () {
    $draft = publishableGameDraft(5);
    $this->actingAs($draft['admin']);
    liveGame($draft);
    $this->travel(1)->minutes();
    $draft['set']->rounds()->orderBy('position')->get()->each(fn (GameRound $round) => $round->forceFill(['question' => 'Which one, round '.($round->position + 1).'?'])->save());

    Livewire::test(GameVersions::class, ['record' => $draft['game']->id])
        ->assertSee('data-testid="game-versions-changed-all"', false)
        ->assertSeeInOrder(['All 5 changes', 'Content set “'.$draft['set']->name.'”', 'Round 1 · Question', 'Round 5 · Question']);
});

it('says on Versions that the set or flow changed exactly when the tab says so', function () {
    liveGame($this->draft);
    $this->travel(1)->minutes();
    $round = $this->draft['set']->rounds()->orderBy('position')->first();
    $round->touch();
    $content = fn () => Livewire::test(EditGameContent::class, ['record' => $this->draft['game']->id]);
    $versions = fn () => Livewire::test(GameVersions::class, ['record' => $this->draft['game']->id]);

    // Saved again as it was: neither says anything changed.
    $content()->assertDontSee('data-testid="game-unpublished"', false);
    $versions()->assertSee('V1 already uses this set and flow, and neither changed since.')
        ->assertDontSee('data-testid="game-versions-changed"', false);

    $round->forceFill(['question' => 'Which one, now?'])->save();

    $content()->assertSee('1 change since V1:')->assertSee('Round 1 · Question');
    $versions()->assertSee('This set or flow, or a translation of it, changed after V1 was published from them: Round 1 · Question.')
        ->assertDontSee('neither changed since');
});

it('names a step’s settings with the labels the Flow editor shows them under', function () {
    $views = collect(File::files(resource_path('views/filament/pages/games/flow')))
        ->map(fn (SplFileInfo $file): string => $file->getContents())
        ->implode("\n");

    foreach (array_unique(VersionChanges::SETTING_LABELS) as $label) {
        expect($views)->toContain($label);
    }
});
