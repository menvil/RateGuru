<?php

namespace App\Filament\Resources\Games\Pages;

use App\Actions\Games\SaveGameGeneralAction;
use App\Actions\Games\SetGameCoverAction;
use App\Data\Games\GameGeneralData;
use App\Exceptions\Games\CannotManageGameException;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Queries\Games\GameUnpublishedChangesQuery;
use App\Services\Media\Exceptions\ImageIngestException;
use App\Support\Games\GameGeneralRules;
use App\Support\Media\PostImagePresenter;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A game's General tab: its public title, internal name, slug and
 * description — a draft until Save in the top bar, which goes through
 * SaveGameGeneralAction; the browser compares what is typed with `saved` to
 * count what is unsaved, and Discard puts the saved values back — and its
 * cover, stored the moment it is chosen (SetGameCoverAction). Once a version
 * has been published the slug is locked: shared links point at it.
 */
final class EditGameGeneral extends Page
{
    use InteractsWithGameEditor;
    use WithFileUploads;

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-general';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    public string $gameTitle = '';

    public string $internalName = '';

    public string $gameSlug = '';

    public string $gameDescription = '';

    /** @var array<string, string> the values as saved, for the browser's count of what is unsaved */
    public array $saved = [];

    /** The game's version as the form was drawn from it. */
    #[Locked]
    public int $lockVersion = 1;

    /** Whether somebody else saved the game since the form was drawn. */
    #[Locked]
    public bool $stale = false;

    #[Locked]
    public bool $gone = false;

    /** A cover being uploaded. */
    public mixed $coverUpload = null;

    public function mount(int|string $record): void
    {
        $game = $this->mountGame($record);
        $this->draw($game);
    }

    #[On('discard-game-general')]
    public function discard(): void
    {
        $this->reload();
    }

    public function reload(): void
    {
        $game = $this->game();
        $this->resetErrorBag();

        if ($game === null) {
            $this->gone = true;

            return;
        }

        $this->draw($game);
    }

    #[On('save-game-general')]
    public function save(): void
    {
        $game = $this->game();

        if ($game === null) {
            $this->gone = true;

            return;
        }

        try {
            app(SaveGameGeneralAction::class)->handle($this->admin(), $game, $this->generalData(), $this->lockVersion);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (CannotManageGameException $exception) {
            $this->stale = $exception->isStale();
            $this->gone = $exception->isGone();
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->draw($game);
        $this->toast('Changes saved');
    }

    /** A chosen cover is stored at once, against the version the form holds. */
    public function updatedCoverUpload(): void
    {
        try {
            $this->validate(['coverUpload' => ['required', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max((int) config('uploads.images.max_kilobytes', 5120))]], [], ['coverUpload' => 'cover']);
        } catch (ValidationException $exception) {
            $this->coverUpload = null;

            throw $exception;
        }

        $game = $this->game();
        $file = $this->coverUpload;
        $this->coverUpload = null;

        if ($game === null || ! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            app(SetGameCoverAction::class)->handle($this->admin(), $game, $file, $this->lockVersion);
        } catch (ImageIngestException $exception) {
            $this->addError('coverUpload', $exception->getMessage());

            return;
        } catch (CannotManageGameException $exception) {
            $this->stale = $exception->isStale();
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->lockVersion = $game->lock_version;
        $this->toast('Cover saved');
    }

    public function removeCover(): void
    {
        $game = $this->game();

        if ($game === null) {
            return;
        }

        try {
            app(SetGameCoverAction::class)->remove($this->admin(), $game, $this->lockVersion);
        } catch (CannotManageGameException $exception) {
            $this->stale = $exception->isStale();
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->lockVersion = $game->lock_version;
        $this->toast('Cover removed', 'info');
    }

    private function draw(Game $game): void
    {
        $data = GameGeneralData::of($game);
        $this->gameTitle = $data->title;
        $this->internalName = $data->internalName;
        $this->gameSlug = $data->slug;
        $this->gameDescription = $data->description;
        $this->saved = [
            'gameTitle' => $data->title,
            'internalName' => $data->internalName,
            'gameSlug' => $data->slug,
            'gameDescription' => $data->description,
        ];
        $this->lockVersion = $game->lock_version;
        $this->stale = false;
        $this->gone = false;
    }

    private function generalData(): GameGeneralData
    {
        return new GameGeneralData(
            title: $this->gameTitle,
            internalName: $this->internalName,
            slug: $this->gameSlug,
            description: $this->gameDescription,
        );
    }

    /** @return array<string, int> */
    private function generalLimits(): array
    {
        return [
            'title' => GameGeneralRules::TITLE_MAX,
            'description' => GameGeneralRules::DESCRIPTION_MAX,
            'slug' => GameGeneralRules::SLUG_MAX,
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $game = $this->game();

        if ($game === null) {
            return ['header' => null, 'limits' => $this->generalLimits(), 'slugLocked' => true, 'mechanic' => null, 'cover' => null, 'stale' => false, 'gone' => true];
        }

        $game->load('coverAsset.variants');
        $asset = $game->coverAsset;

        return [
            'header' => $this->editorHeader($game, 'general', unpublished: app(GameUnpublishedChangesQuery::class)->forGame($game)),
            'limits' => $this->generalLimits(),
            'slugLocked' => $game->hasBeenPublished(),
            'mechanic' => $game->mechanic,
            'cover' => $asset === null ? null : [
                'url' => app(PostImagePresenter::class)->assetPreviewUrl($asset),
                'name' => is_string($asset->original_filename) && $asset->original_filename !== '' ? $asset->original_filename : 'Cover image',
                'size' => is_int($asset->width) && is_int($asset->height) ? $asset->width.' × '.$asset->height : null,
            ],
            'stale' => $this->stale,
            'gone' => $this->gone,
        ];
    }
}
