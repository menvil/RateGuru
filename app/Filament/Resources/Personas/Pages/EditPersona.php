<?php

namespace App\Filament\Resources\Personas\Pages;

use App\Actions\Personas\UpdatePersonaAction;
use App\Data\Personas\PersonaData;
use App\Exceptions\Personas\CannotManagePersonaException;
use App\Filament\Resources\Personas\Concerns\EditsPersona;
use App\Filament\Resources\Personas\PersonaResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ModerationListFormat;
use App\Models\User;
use App\Queries\Users\UserModerationProfileQuery;
use App\Support\Media\AvatarUrlResolver;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * One Persona's editor in Admin v2: its public profile and what Content
 * Studio keeps about it, saved from the top bar through UpdatePersonaAction
 * (and its photo through SetPersonaAvatarAction), beside where the account
 * stands — its status, which only the moderation actions in Users change,
 * and its public profile.
 *
 * The editor remembers what it was drawn from (`fingerprint`, and the avatar
 * it showed): a save after somebody else's — a photo alone included — is
 * refused and says so, and Reload draws the Persona as it is now. A Persona
 * deleted, or no longer a Persona, meanwhile can no longer be saved at all.
 */
final class EditPersona extends Page
{
    use EditsPersona;

    protected static string $resource = PersonaResource::class;

    protected string $view = 'filament.pages.persona';

    // A screen drawn in Admin v2 brings its own header band and gutter.
    protected Width|string|null $maxContentWidth = 'rg-admin-main';

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

    public function mount(int|string $record): void
    {
        $persona = is_numeric($record) ? User::query()->with('personaProfile')->find((int) $record) : null;

        abort_unless(PersonaResource::canAccess(), 403);
        abort_unless($persona !== null && PersonaResource::canEdit($persona), 404);

        $this->personaId = $persona->id;
        $this->draw($persona);

        // Created a moment ago, on the page that redirected here.
        $created = session('rg-admin-toast');

        if (is_array($created) && is_string($created['message'] ?? null)) {
            $this->toast($created['message'], is_string($created['tone'] ?? null) ? $created['tone'] : 'success');
        }
    }

    public function getTitle(): string
    {
        return $this->saved['displayName'] !== '' ? (string) $this->saved['displayName'] : 'Persona';
    }

    #[On('discard-persona')]
    public function discard(): void
    {
        $this->fillDraft($this->saved);
        $this->resetErrorBag();
    }

    /** Draws the Persona as it is now, the draft with it. */
    #[On('reload-persona')]
    public function reload(): void
    {
        $persona = User::query()->with('personaProfile')->find($this->personaId);

        if ($persona === null || ! PersonaResource::canEdit($persona)) {
            $this->gone = true;

            return;
        }

        $this->draw($persona);
        $this->resetErrorBag();
    }

    #[On('save-persona')]
    public function save(): void
    {
        if ($this->gone) {
            return;
        }

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

        match (true) {
            $photoSaved => $this->toast('Changes to '.$this->saved['displayName'].' saved'),
            $changed => $this->toast('Changes saved, but the photo wasn’t.', 'error'),
            default => $this->toast('The photo wasn’t saved.', 'error'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $persona = $this->persona();
        $profile = app(UserModerationProfileQuery::class);
        $creator = $persona->personaProfile?->createdBy;

        return [
            'creating' => false,
            'persona' => [
                'displayName' => (string) $this->saved['displayName'],
                'username' => (string) $persona->username,
                'status' => ModerationListFormat::personStatus($persona),
                'avatar' => app(AvatarUrlResolver::class)->url($persona),
                'initials' => ModerationListFormat::initials((string) ($this->saved['displayName'] ?: $persona->username)),
                'posts' => number_format(array_sum($profile->postsByStatus($persona))),
                'comments' => number_format($profile->comments($persona)),
                'created' => $persona->created_at?->format('d M Y'),
                'createdBy' => $creator !== null ? (string) $creator->username : null,
                'profileUrl' => route('profile.show', ['username' => $persona->username]),
                'usersUrl' => UserResource::canEdit($persona) ? UserResource::getUrl('edit', ['record' => $persona]) : null,
            ],
            ...$this->editorViewData(app(AvatarUrlResolver::class)->url($persona)),
        ];
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
            $this->dispatch('rg-admin-toast',
                message: $restSaved
                    ? 'Changes saved, but someone else saved this persona meanwhile, so the photo wasn’t.'
                    : 'Someone saved this persona after you opened it, so nothing was saved.',
                tone: 'error',
                action: ['label' => 'Reload', 'event' => 'reload-persona', 'params' => []],
            );

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

    private function persona(): User
    {
        $persona = User::query()->with('personaProfile.createdBy')->find($this->personaId);

        abort_if($persona === null, 404);

        return $persona;
    }
}
