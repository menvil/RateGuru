<?php

namespace App\Livewire\Admin;

use App\Actions\Personas\CreatePersonaAction;
use App\Actions\Personas\UpdatePersonaAction;
use App\Data\Personas\PersonaData;
use App\Exceptions\Auth\CannotGenerateUsernameException;
use App\Exceptions\Personas\CannotManagePersonaException;
use App\Filament\Resources\Personas\Concerns\EditsPersona;
use App\Filament\Resources\Personas\PersonaResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ModerationListFormat;
use App\Models\User;
use App\Queries\Users\UserModerationProfileQuery;
use App\Support\Media\AvatarUrlResolver;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A Persona's editor, in the drawer over Content Studio's Personas: a new
 * one made through CreatePersonaAction, or one edited through
 * UpdatePersonaAction — its photo through SetPersonaAvatarAction either way —
 * saved from the drawer's own footer, with no page of its own. The list
 * opens it (`?persona=new`, `?persona=12`), and closes it once it is saved.
 *
 * The editor remembers what it was drawn from (`fingerprint`, and the avatar
 * it showed): a save after somebody else's — a photo alone included — is
 * refused and says so, and Reload draws the Persona as it is now. A Persona
 * deleted, or no longer a Persona, meanwhile can no longer be saved at all.
 * Every request asks again that the viewer may manage Personas.
 */
final class PersonaEditor extends Component
{
    use EditsPersona;
    use WithFileUploads;

    /** The Persona edited; 0 while a new one is made. */
    #[Locked]
    public int $personaId = 0;

    /** PersonaData::fingerprint() of the Persona as the draft was drawn from it. */
    #[Locked]
    public string $fingerprint = '';

    /** The avatar the Persona had when the draft was drawn. */
    #[Locked]
    public ?int $avatarAssetId = null;

    /** Whether somebody else saved the Persona since the draft was drawn. */
    #[Locked]
    public bool $stale = false;

    /** Whether the account was deleted, or stopped being a Persona, meanwhile. */
    #[Locked]
    public bool $gone = false;

    public function boot(): void
    {
        abort_unless(PersonaResource::canAccess(), 403);
    }

    public function mount(int $personaId = 0): void
    {
        if ($personaId === 0) {
            $this->saved = self::savedValuesOf(new PersonaData(displayName: ''));
            $this->fillDraft($this->saved);

            return;
        }

        $persona = User::query()->with('personaProfile')->find($personaId);

        abort_unless($persona !== null && PersonaResource::canEdit($persona), 404);

        $this->personaId = $persona->id;
        $this->draw($persona);
    }

    /** Draws the Persona as it is now, the draft with it. */
    public function reload(): void
    {
        $persona = $this->personaId !== 0 ? User::query()->with('personaProfile')->find($this->personaId) : null;

        if ($persona === null || ! PersonaResource::canEdit($persona)) {
            $this->gone = $this->personaId !== 0;

            return;
        }

        $this->draw($persona);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        if ($this->gone) {
            return;
        }

        $this->personaId === 0 ? $this->create() : $this->update();
    }

    public function render(): View
    {
        $persona = $this->personaId !== 0 ? User::query()->with('personaProfile.createdBy')->find($this->personaId) : null;
        $avatarUrl = $persona !== null ? app(AvatarUrlResolver::class)->url($persona) : null;

        return view('livewire.admin.persona-editor', [
            'creating' => $this->personaId === 0,
            'persona' => $persona === null ? null : $this->personaDetails($persona, $avatarUrl),
            ...$this->editorViewData($avatarUrl),
        ]);
    }

    /** Makes the Persona, then gives it the chosen photo, since its file is stored under the account it belongs to. */
    private function create(): void
    {
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

        // From now on this editor is the new Persona's, so Save sent twice
        // never makes a second one.
        $this->personaId = $persona->id;
        $created = PersonaData::of($persona, $persona->personaProfile);

        try {
            $photoSaved = $this->savePhoto($persona, $created->fingerprint(), null);
        } catch (CannotManagePersonaException) {
            // Changed, deleted or put out of reach the moment it was made:
            // its editor says which when it opens.
            $photoSaved = false;
        }

        $this->draw($persona->fresh(['personaProfile']) ?? $persona);

        $photoSaved
            ? $this->dispatch('persona-saved', message: $persona->display_name.' created')
            // Opened again on the new Persona, for the photo to be chosen again.
            : $this->dispatch('persona-saved', message: $persona->display_name.' created, but its photo wasn’t saved. Choose it again.', tone: 'error', reopen: $persona->id);
    }

    private function update(): void
    {
        $persona = $this->persona();

        $this->validateDraft($persona);

        $draft = $this->draftData();
        $changed = $draft->fingerprint() !== $this->fingerprint;
        $fingerprint = $this->fingerprint;

        if ($changed) {
            try {
                app(UpdatePersonaAction::class)->handle($this->admin(), $persona, $draft, $this->fingerprint);
            } catch (CannotManagePersonaException $exception) {
                $this->refuse($exception);

                return;
            }

            // The photo is asked against what this save has just written, so
            // the save is no conflict with itself.
            $fingerprint = PersonaData::of($persona, $persona->personaProfile)->fingerprint();
        }

        try {
            $photoSaved = $this->savePhoto($persona, $fingerprint, $this->avatarAssetId);
        } catch (CannotManagePersonaException $exception) {
            if ($changed) {
                // What this save wrote stands; the photo is all it lost.
                $this->draw($persona);
            }

            $this->refuse($exception, $changed);

            return;
        }

        $this->draw($persona->fresh(['personaProfile']) ?? $persona);

        if ($photoSaved) {
            $this->dispatch('persona-saved', message: 'Changes to '.$this->saved['displayName'].' saved');

            return;
        }

        // The photo stays to be chosen again, with what was saved drawn.
        $this->toast($changed ? 'Changes saved, but the photo wasn’t.' : 'The photo wasn’t saved.', 'error');
    }

    /**
     * Says why nothing — or, once the rest was saved, only the photo — was
     * saved, by the field where there is one.
     */
    private function refuse(CannotManagePersonaException $exception, bool $restSaved = false): void
    {
        if ($exception->isUsernameTaken()) {
            $this->addError('username', 'Another account already uses this username. Choose another one.');

            return;
        }

        if ($exception->isStale()) {
            $this->stale = true;
            $this->toast($restSaved
                ? 'Changes saved, but someone else saved this persona meanwhile, so the photo wasn’t.'
                : 'Someone saved this persona after you opened it, so nothing was saved.', 'error');

            return;
        }

        $what = $restSaved ? 'the photo wasn’t saved' : 'nothing was saved';

        if ($exception->isGone()) {
            $this->gone = true;
            $this->toast("This persona can’t be edited any more, so {$what}.", 'error');

            return;
        }

        $this->toast("You can’t manage personas any more, so {$what}.", 'error');
    }

    private function draw(User $persona): void
    {
        $saved = PersonaData::of($persona, $persona->personaProfile);

        $this->fingerprint = $saved->fingerprint();
        $this->avatarAssetId = $persona->avatar_asset_id !== null ? (int) $persona->avatar_asset_id : null;
        $this->saved = self::savedValuesOf($saved);
        $this->stale = false;
        $this->fillDraft($this->saved);
    }

    /**
     * Where the account stands, as the drawer shows it beside the draft.
     *
     * @return array<string, mixed>
     */
    private function personaDetails(User $persona, ?string $avatarUrl): array
    {
        $profile = app(UserModerationProfileQuery::class);
        $creator = $persona->personaProfile?->createdBy;

        return [
            'displayName' => (string) $this->saved['displayName'],
            'username' => (string) $persona->username,
            'status' => ModerationListFormat::personStatus($persona),
            'avatar' => $avatarUrl,
            'initials' => ModerationListFormat::initials((string) ($this->saved['displayName'] ?: $persona->username)),
            'posts' => number_format(array_sum($profile->postsByStatus($persona))),
            'comments' => number_format($profile->comments($persona)),
            'created' => $persona->created_at?->format('d M Y'),
            'createdBy' => $creator !== null ? (string) $creator->username : null,
            'profileUrl' => route('profile.show', ['username' => $persona->username]),
            'usersUrl' => UserResource::canEdit($persona) ? ListUsers::getUrl(['user' => $persona->id]) : null,
        ];
    }

    private function persona(): User
    {
        $persona = User::query()->with('personaProfile.createdBy')->find($this->personaId);

        abort_if($persona === null, 404);

        return $persona;
    }
}
