<?php

namespace App\Filament\Resources\Games\Pages;

use App\Actions\Games\CreateGameAction;
use App\Data\Games\GameGeneralData;
use App\Enums\GameMechanic;
use App\Exceptions\Games\CannotManageGameException;
use App\Filament\Resources\Games\Concerns\EditsGameGeneral;
use App\Filament\Resources\Games\GameResource;
use App\Models\User;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

/**
 * A new game, in Admin v2: its General tab before anything is saved. Create
 * game in the top bar makes a Draft through CreateGameAction — with an empty
 * content set and a Result First flow — and its editor opens. Nothing is
 * published.
 */
final class CreateGame extends Page
{
    use EditsGameGeneral;

    protected static string $resource = GameResource::class;

    protected string $view = 'filament.pages.game-general';

    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static ?string $title = 'New game';

    public function mount(): void
    {
        abort_unless(GameResource::canCreate(), 403);

        $this->fillGeneral(new GameGeneralData);
    }

    #[On('discard-game-general')]
    public function discard(): void
    {
        $this->fillGeneral(new GameGeneralData);
        $this->slugTyped = false;
        $this->resetErrorBag();
    }

    #[On('save-game-general')]
    public function save(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            $game = app(CreateGameAction::class)->handle($user, $this->generalData());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (CannotManageGameException $exception) {
            $this->dispatch('rg-admin-toast', message: $exception->getMessage(), tone: 'error');

            return;
        }

        session()->flash('rg-admin-toast', ['message' => 'Game created as a draft', 'tone' => 'success']);
        $this->redirect(GameResource::getUrl('edit', ['record' => $game]));
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'creating' => true,
            'header' => null,
            'limits' => $this->generalLimits(),
            'slugLocked' => false,
            'mechanic' => GameMechanic::Versus,
            'cover' => null,
            'stale' => false,
            'gone' => false,
        ];
    }
}
