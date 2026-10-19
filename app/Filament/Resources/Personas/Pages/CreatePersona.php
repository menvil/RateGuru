<?php

namespace App\Filament\Resources\Personas\Pages;

use App\Actions\Personas\CreatePersonaAction;
use App\Data\Personas\PersonaData;
use App\Exceptions\Auth\CannotGenerateUsernameException;
use App\Exceptions\Personas\CannotManagePersonaException;
use App\Filament\Resources\Personas\Concerns\EditsPersona;
use App\Filament\Resources\Personas\PersonaResource;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * A new Persona, in Admin v2: its public profile and what Content Studio
 * keeps about it, made with Create persona in the top bar through
 * CreatePersonaAction, which sets everything else itself. The new Persona's
 * editor opens next; a photo chosen here is given to it right after it is
 * created, since its file is stored under the account it belongs to.
 */
final class CreatePersona extends Page
{
    use EditsPersona;

    protected static string $resource = PersonaResource::class;

    protected string $view = 'filament.pages.persona';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

    protected static ?string $title = 'New persona';

    /** The Persona this page made, so a Save sent twice never makes a second one. */
    #[Locked]
    public ?int $createdId = null;

    public function mount(): void
    {
        $this->saved = self::savedValuesOf(new PersonaData(displayName: ''));
        $this->fillDraft($this->saved);
    }

    #[On('discard-persona')]
    public function discard(): void
    {
        $this->fillDraft($this->saved);
        $this->resetErrorBag();
    }

    #[On('save-persona')]
    public function save(): void
    {
        if ($this->createdId !== null) {
            $this->redirect(EditPersona::getUrl(['record' => $this->createdId]));

            return;
        }

        $this->validateDraft(null);

        try {
            $persona = app(CreatePersonaAction::class)->handle($this->admin(), $this->draftData());
        } catch (CannotManagePersonaException $exception) {
            if ($exception->isUsernameTaken()) {
                $this->addError('username', 'Another account already uses this username. Choose another one.');
            } else {
                $this->toast('You can’t create personas any more, so nothing was created.', 'error');
            }

            return;
        } catch (CannotGenerateUsernameException) {
            $this->addError('username', 'No username could be made from this display name. Enter one.');

            return;
        }

        $this->createdId = $persona->id;
        $created = PersonaData::of($persona, $persona->personaProfile);

        try {
            $photoSaved = $this->savePhoto($persona, $created->fingerprint(), null);
        } catch (CannotManagePersonaException) {
            // Changed, deleted or put out of reach the moment it was made:
            // its editor says which.
            $photoSaved = false;
        }

        // Everything on the page is saved now, so leaving it for the editor
        // is not leaving unsaved changes behind.
        $this->saved = self::savedValuesOf($created);
        $this->fillDraft($this->saved);

        session()->flash('rg-admin-toast', $photoSaved
            ? ['message' => $persona->display_name.' created', 'tone' => 'success']
            : ['message' => $persona->display_name.' created, but its photo wasn’t saved. Choose it again.', 'tone' => 'error']);

        $this->redirect(EditPersona::getUrl(['record' => $persona->id]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'creating' => true,
            'persona' => null,
            ...$this->editorViewData(null),
        ];
    }
}
