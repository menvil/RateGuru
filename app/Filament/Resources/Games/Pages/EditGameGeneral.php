<?php

namespace App\Filament\Resources\Games\Pages;

use App\Actions\Games\SaveGameGeneralAction;
use App\Actions\Games\SetGameCoverAction;
use App\Data\Games\GameGeneralData;
use App\Exceptions\Games\CannotManageGameException;
use App\Filament\Resources\Games\Concerns\EditsGameGeneral;
use App\Filament\Resources\Games\Concerns\InteractsWithGameEditor;
use App\Filament\Resources\Games\GameResource;
use App\Models\Game;
use App\Services\Media\Exceptions\ImageIngestException;
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
 * description, saved from the top bar through SaveGameGeneralAction; and its
 * cover, stored the moment it is chosen (SetGameCoverAction). Once a version
 * has been published the slug and the mechanic are locked: shared links
 * point at the slug.
 */
final class EditGameGeneral extends Page
{
    use EditsGameGeneral;
    use InteractsWithGameEditor;
    use WithFileUploads;

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-general';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

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

    protected function slugIsFree(): bool
    {
        return ! ($this->game()?->hasBeenPublished() ?? false);
    }

    private function draw(Game $game): void
    {
        $this->fillGeneral(GameGeneralData::of($game));
        $this->slugTyped = true;
        $this->lockVersion = $game->lock_version;
        $this->stale = false;
        $this->gone = false;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $game = $this->game();

        if ($game === null) {
            return ['creating' => false, 'header' => null, 'limits' => $this->generalLimits(), 'slugLocked' => true, 'mechanic' => null, 'cover' => null, 'stale' => false, 'gone' => true];
        }

        $game->load('coverAsset.variants');

        return [
            'creating' => false,
            'header' => $this->editorHeader($game, 'general'),
            'limits' => $this->generalLimits(),
            'slugLocked' => $game->hasBeenPublished(),
            'mechanic' => $game->mechanic,
            'cover' => $game->coverAsset !== null ? app(PostImagePresenter::class)->assetPreviewUrl($game->coverAsset) : null,
            'stale' => $this->stale,
            'gone' => $this->gone,
        ];
    }
}
