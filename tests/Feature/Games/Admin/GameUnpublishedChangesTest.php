<?php

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
use Livewire\Livewire;

/*
 * Saving changes the draft, never what the players play: a tab whose saved
 * changes they don't see says which version they play instead, and opens
 * Versions set to publish one that has them in its place.
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
        ->assertSee('They play V1, published before them. Publish V2 to put them live')
        ->assertSee('href="'.publishUrlFor($this->draft).'"', false);
});

it('says on Flow when a step was saved after the version the players play', function () {
    liveGame($this->draft);
    $page = fn () => Livewire::test(EditGameFlow::class, ['record' => $this->draft['game']->id]);

    $page()->assertDontSee('data-testid="game-unpublished"', false);

    $this->travel(1)->minutes();
    GameFlowStep::query()->where('flow_id', $this->draft['flow']->id)->where('type', 'full_result')->sole()->update(['title' => 'Join us']);

    $page()->assertSee('They play V1, published before them.')
        ->assertSee('href="'.publishUrlFor($this->draft).'"', false);
});

it('says on General when the title, a translation of it, the description or the cover differ from what the players play', function (Closure $change) {
    liveGame($this->draft);
    $page = fn () => Livewire::test(EditGameGeneral::class, ['record' => $this->draft['game']->id]);

    // Saved again unchanged, or its status moved: nothing the players miss.
    $this->travel(1)->minutes();
    $this->draft['game']->fresh()->touch();
    $page()->assertDontSee('data-testid="game-unpublished"', false);

    $change($this->draft['game']->fresh());

    $page()->assertSee('They play V1, published before them.');
})->with([
    'the title' => [fn ($game) => $game->forceFill(['title' => 'Trust Your Gut'])->save()],
    'a translation of the title' => [fn ($game) => $game->forceFill(['title_translations' => ['de' => 'Vertrau deinem Geschmack']])->save()],
    'the description' => [fn ($game) => $game->forceFill(['description' => 'Something else'])->save()],
    'the cover' => [fn ($game) => $game->forceFill(['cover_asset_id' => MediaAsset::factory()->create()->id])->save()],
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
