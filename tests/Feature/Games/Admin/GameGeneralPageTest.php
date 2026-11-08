<?php

use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\CreateGame;
use App\Filament\Resources\Games\Pages\EditGameGeneral;
use App\Models\Game;
use App\Models\MediaAsset;
use App\Models\ProjectSettings;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    ProjectSettings::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('draws a new game with Create game in the top bar', function () {
    $this->get(GameResource::getUrl('create'))
        ->assertOk()
        ->assertSee('New game')
        ->assertSee('Create game')
        ->assertSee('Creating the game publishes nothing.');
});

it('makes the slug from the title until the slug is typed', function () {
    Livewire::test(CreateGame::class)
        ->set('gameTitle', 'Trust Your Taste!')
        ->assertSet('gameSlug', 'trust-your-taste')
        ->set('gameSlug', 'my-own')
        ->set('gameTitle', 'Something else')
        ->assertSet('gameSlug', 'my-own');
});

it('creates the game as a draft and opens its editor', function () {
    Livewire::test(CreateGame::class)
        ->set('gameTitle', 'Trust Your Taste')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(GameResource::getUrl('edit', ['record' => Game::query()->sole()]));

    expect(Game::query()->sole()->slug)->toBe('trust-your-taste');
});

it('shows the rules a new game breaks, keyed to their fields', function () {
    Livewire::test(CreateGame::class)
        ->set('gameTitle', '')
        ->set('gameSlug', 'Bad Slug')
        ->call('save')
        ->assertHasErrors(['title', 'slug']);

    expect(Game::query()->exists())->toBeFalse();
});

it('saves the general fields from the top bar, and refuses to overwrite a newer save', function () {
    $game = Game::factory()->create(['title' => 'Before']);

    $page = Livewire::test(EditGameGeneral::class, ['record' => $game->id])
        ->assertSet('gameTitle', 'Before')
        ->set('gameTitle', 'After')
        ->call('save')
        ->assertDispatched('rg-admin-toast', message: 'Changes saved', tone: 'success');

    expect($game->fresh()->title)->toBe('After');

    Game::query()->whereKey($game->id)->update(['lock_version' => 99]);

    $page->set('gameTitle', 'Lost')->call('save')->assertSet('stale', true);

    expect($game->fresh()->title)->toBe('After');
});

it('locks the slug on screen once a version has been published', function () {
    $game = Game::factory()->published()->create();

    $this->get(GameResource::getUrl('edit', ['record' => $game]))
        ->assertOk()
        ->assertSee('Locked after the first publish');
});

it('stores a cover the moment it is chosen, and releases it when removed', function () {
    $game = Game::factory()->create();

    $page = Livewire::test(EditGameGeneral::class, ['record' => $game->id])
        ->set('coverUpload', UploadedFile::fake()->image('cover.jpg', 1600, 900))
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Cover saved', tone: 'success');

    $asset = MediaAsset::query()->find($game->fresh()->cover_asset_id);

    expect($asset)->not->toBeNull();

    $page->call('removeCover');

    expect($game->fresh()->cover_asset_id)->toBeNull()
        ->and($asset->fresh()->trashed())->toBeTrue();
});

it('opens no editor for a game that does not exist, nor for a moderator', function () {
    $this->get(GameResource::getUrl('edit', ['record' => 999_999]))->assertNotFound();

    $this->actingAs(User::factory()->moderator()->create())
        ->get(GameResource::getUrl('edit', ['record' => Game::factory()->create()]))
        ->assertForbidden();
});
