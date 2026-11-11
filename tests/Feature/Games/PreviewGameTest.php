<?php

use App\Enums\GameFlowStepType;
use App\Filament\Resources\Games\GameResource;
use App\Livewire\Games\PreviewGame;
use App\Models\GameAnswer;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Models\GameFlowStep;
use App\Models\GameResultProfile;
use App\Models\GameRound;
use App\Models\MediaAsset;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Urls\DisplayHost;
use Illuminate\Support\Facades\Blade;
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

it('frames a pair as its pictures are, and every pair in one shape when asked', function () {
    $assets = $this->draft['set']->rounds()->first()->options()->with('post')->get()->map(fn ($option): int => (int) $option->post->image_asset_id);
    $round = fn (array $query = []) => Livewire::withQueryParams(['start' => 'round:0', ...$query])->test(PreviewGame::class, ['game' => $this->draft['game']->id]);

    MediaAsset::query()->whereIn('id', $assets)->update(['width' => 1600, 'height' => 900]);
    $round()->assertSee('rg-game__pair rg-game__pair--landscape', false);

    MediaAsset::query()->whereIn('id', $assets)->update(['width' => 900, 'height' => 1600]);
    $round()->assertSee('rg-game__pair rg-game__pair--portrait', false);

    MediaAsset::query()->whereKey($assets->first())->update(['width' => 1600, 'height' => 900]);
    $round()->assertSee('rg-game__pair rg-game__pair--square', false);

    $round(['shape' => 'landscape'])->assertSee('rg-game__pair rg-game__pair--landscape', false);
    $round(['shape' => 'sideways'])->assertSee('rg-game__pair rg-game__pair--square', false);
});

it('starts at any step of the flow, one after the game with every round played as the crowd did', function () {
    $steps = $this->draft['flow']->steps()->get();

    Livewire::withQueryParams(['start' => 'step:'.$steps->last()->key()])->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSee('You matched the crowd in 2 of 2 picks');

    Livewire::withQueryParams(['start' => 'step:'.$steps->first()->key()])->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSee('Start playing');
});

it('plays as an anonymous visitor unless a logged-in player is asked for', function () {
    Livewire::withQueryParams(['visitor' => 'authenticated'])->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSet('visitorMode', 'authenticated');

    Livewire::withQueryParams(['visitor' => 'known'])->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSet('visitorMode', 'anonymous');
});

it('puts the profile\'s description large above the result card, the site\'s address on it, and no agreement line', function () {
    Livewire::withQueryParams(['start' => 'result:2'])->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSeeInOrder(['game-profile-description', 'You matched every choice.', 'rg-game__share-card', 'Oracle', 'You matched the crowd in 2 of 2 picks · <span class="rg-game__share-card-host" data-testid="game-byline">'.DisplayHost::of(url('/')).'</span>'], false)
        ->assertDontSee('agreement')
        ->assertDontSee('rg-game__eyebrow', false);
});

it('shows the teaser under the player\'s numbers, or in their place on one line', function () {
    $flow = $this->draft['flow'];
    $flow->steps()->where('position', 2)->update(['position' => 3]);
    $step = GameFlowStep::factory()->type(GameFlowStepType::ResultPreview)->create(['flow_id' => $flow->id, 'position' => 2]);
    $preview = fn () => Livewire::withQueryParams(['start' => 'step:'.$step->key()])->test(PreviewGame::class, ['game' => $this->draft['game']->id]);

    $preview()
        ->assertSeeInOrder(['You matched 2 of 2', 'Your result profile is ready.', 'See my profile'])
        ->assertDontSee('rg-game__title--line', false)
        ->assertDontSee('rg-game__eyebrow', false);

    $step->update(['settings' => ['show_partial' => false]]);

    $preview()
        ->assertSeeInOrder(['rg-game__title rg-game__title--line', '--rg-game-line-chars: 29', 'Your result profile is ready.'], false)
        ->assertDontSee('You matched');
});

it('leaves out its banner inside an editor\'s frame, which says itself that it is a preview', function () {
    Livewire::withQueryParams(['framed' => 1])->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertDontSee('nothing is recorded');

    Livewire::withQueryParams([])->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSee('Preview — draft — nothing is recorded');
});

it('gives a profile the result step hides away by neither its picture nor its sign', function () {
    $step = GameFlowStep::query()->where('flow_id', $this->draft['flow']->id)->where('type', 'full_result')->sole();
    $step->forceFill(['settings' => [...$step->settings, 'show_profile' => false]])->save();
    $picture = MediaAsset::factory()->create();
    GameResultProfile::query()->where('key', 'oracle')->update(['share_asset_id' => $picture->id]);
    $result = fn () => Livewire::withQueryParams(['start' => 'result:2'])->test(PreviewGame::class, ['game' => $this->draft['game']->id]);

    $result()->assertDontSee($picture->path)
        ->assertSeeHtml(Blade::render('<x-ui.icon name="award" class="size-16" />'))
        ->assertDontSeeHtml(Blade::render('<x-ui.icon name="sparkles" class="size-16" />'));

    $cover = MediaAsset::factory()->create();
    $this->draft['game']->forceFill(['cover_asset_id' => $cover->id])->save();

    $result()->assertSee($cover->path)->assertDontSee($picture->path);
});
