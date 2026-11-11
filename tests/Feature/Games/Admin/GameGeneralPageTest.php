<?php

use App\Enums\GameStatus;
use App\Filament\Resources\Games\GameResource;
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

it('offers no page that makes a game', function () {
    expect(GameResource::canCreate())->toBeFalse()
        ->and(GameResource::hasPage('create'))->toBeFalse();

    $this->get('/admin/games/create')->assertNotFound();
});

it('shows the rules the general fields break, keyed to their fields, and saves nothing', function () {
    $game = Game::factory()->create(['title' => 'Before']);

    Livewire::test(EditGameGeneral::class, ['record' => $game->id])
        ->set('gameTitle', '')
        ->set('gameSlug', 'Bad Slug')
        ->call('save')
        ->assertHasErrors(['title', 'slug']);

    expect($game->fresh()->title)->toBe('Before');
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
        ->assertDispatched('rg-admin-toast', message: 'Cover saved', tone: 'success')
        ->assertSee('cover.jpg')
        ->assertSee('1600 × 900 · Landscape')
        ->assertSee('Replace');

    $asset = MediaAsset::query()->find($game->fresh()->cover_asset_id);

    expect($asset)->not->toBeNull();

    $page->call('removeCover');

    expect($game->fresh()->cover_asset_id)->toBeNull()
        ->and($asset->fresh()->trashed())->toBeTrue();
});

it('draws the game’s icon, its mechanic’s, in the header band, and names the mechanic under the title', function () {
    $game = Game::factory()->create(['title' => 'Trust Your Taste', 'slug' => 'trust-your-taste']);

    $this->get(GameResource::getUrl('edit', ['record' => $game]))
        ->assertOk()
        ->assertSee('<span class="rg-admin-games__glyph rg-admin-games__glyph--lg" title="Versus">', false)
        ->assertSeeInOrder(['Trust Your Taste', 'rg-admin-games__meta-item', 'Versus', '/games/trust-your-taste'], false);
});

it('keeps the cover among the general fields, and shows an example round of the mechanic beside them', function () {
    $game = Game::factory()->create(['title' => 'Trust Your Taste']);

    $this->get(GameResource::getUrl('edit', ['record' => $game]))
        ->assertOk()
        ->assertSeeInOrder(['General information', 'Public title', 'Description', 'Cover image', 'No cover yet', 'Upload cover', 'Example round', 'Versus', 'Round 1', 'Trust Your Taste', 'Choose between two images.'])
        ->assertDontSee('How the game is played.');
});

it('archives the game from its status menu, asked first', function () {
    $game = Game::factory()->create();

    Livewire::test(EditGameGeneral::class, ['record' => $game->id])
        ->call('askStatus', 'archived')
        ->assertSee('Archive this game?')
        ->call('changeStatus')
        ->assertDispatched('rg-admin-toast', message: 'The game is archived', tone: 'success');

    expect($game->fresh()->status)->toBe(GameStatus::Archived);
});

it('confirms a pause or an archive with the primary button, as either can be taken back', function (string $target, string $button) {
    $game = Game::factory()->status(GameStatus::Live)->create();

    $html = Livewire::test(EditGameGeneral::class, ['record' => $game->id])
        ->call('askStatus', $target)
        ->assertSee($button)
        ->assertDontSeeHtml('rg-admin-button--danger')
        ->html();

    expect($html)->toMatch('#<button[^>]*class="rg-admin-button rg-admin-button--primary rg-admin-button--md"[^>]*wire:click="changeStatus"#');
})->with([
    'pause' => ['paused', 'Pause game'],
    'archive' => ['archived', 'Archive game'],
]);

it('says why the game cannot go live yet instead of making it live', function () {
    $game = Game::factory()->create();

    Livewire::test(EditGameGeneral::class, ['record' => $game->id])
        ->call('askStatus', 'live')
        ->call('changeStatus')
        ->assertDispatched('rg-admin-toast', message: 'Publish a version and give it a share in Traffic first.', tone: 'error');

    expect($game->fresh()->status)->toBe(GameStatus::Draft);
});

it('opens no editor for a game that does not exist, nor for a moderator', function () {
    $this->get(GameResource::getUrl('edit', ['record' => 999_999]))->assertNotFound();

    $this->actingAs(User::factory()->moderator()->create())
        ->get(GameResource::getUrl('edit', ['record' => Game::factory()->create()]))
        ->assertForbidden();
});
