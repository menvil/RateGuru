<?php

namespace App\Filament\Resources\Personas\Concerns;

use App\Actions\Personas\SetPersonaAvatarAction;
use App\Data\Personas\PersonaData;
use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use App\Exceptions\Personas\CannotManagePersonaException;
use App\Filament\Support\ModerationListFormat;
use App\Models\PersonaProfile;
use App\Models\User;
use App\Services\Media\Exceptions\ImageIngestException;
use App\Support\Personas\PersonaValidationRules;
use App\Support\Profile\ProfileValidationRules;
use Illuminate\Validation\Rules\File;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * A Persona as its editor holds it (PersonaEditor, the drawer over
 * Personas): the public profile — photo, display name, username, bio — and
 * what Content Studio keeps about it — its character, speech, topics, what it
 * never writes about, its emoji, and how readily it would reply on its own.
 *
 * What is typed is a draft until Save in the drawer's footer: the browser
 * compares it to `saved` to count what is unsaved. A chosen photo is part of
 * the draft. The draft is checked with
 * the rules the Content Studio actions check it with again
 * (PersonaValidationRules); nothing in it can carry a role, a status or an
 * address.
 */
trait EditsPersona
{
    public string $displayName = '';

    public string $username = '';

    public string $bio = '';

    public string $shortDescription = '';

    public string $character = '';

    public string $speechStyle = '';

    public string $topics = '';

    public string $neverWritesAbout = '';

    public string $emojiFrequency = 'none';

    public string $engagement = 'off';

    public mixed $maxRepliesPerDay = 0;

    /**
     * A new photo chosen for the Persona, kept until Save.
     *
     * @var TemporaryUploadedFile|null
     */
    public $photo = null;

    /**
     * The draft as saved, keyed as the browser compares it.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $saved = [];

    /** What each engagement level means, as the editor says it. */
    public const ENGAGEMENT_HELP = [
        'off' => 'Writes nothing on its own. Prepared comments in approved publication plans still run while its account allows them.',
        'low' => 'Replies on its own now and then.',
        'normal' => 'Replies on its own at the usual rate.',
        'high' => 'Replies on its own more often than most.',
    ];

    /** Takes the photo off the draft. */
    public function clearPhoto(): void
    {
        $this->photo = null;
        $this->resetErrorBag('photo');
    }

    /** @param  array<string, mixed>  $values */
    protected function fillDraft(array $values): void
    {
        $this->displayName = (string) $values['displayName'];
        $this->username = (string) $values['username'];
        $this->bio = (string) $values['bio'];
        $this->shortDescription = (string) $values['shortDescription'];
        $this->character = (string) $values['character'];
        $this->speechStyle = (string) $values['speechStyle'];
        $this->topics = (string) $values['topics'];
        $this->neverWritesAbout = (string) $values['neverWritesAbout'];
        $this->emojiFrequency = (string) $values['emojiFrequency'];
        $this->engagement = (string) $values['engagement'];
        $this->maxRepliesPerDay = (int) $values['maxRepliesPerDay'];
        $this->photo = null;
    }

    /**
     * A Persona's values as the editor compares them: every text as text.
     *
     * @return array<string, mixed>
     */
    protected static function savedValuesOf(PersonaData $data): array
    {
        return [
            ...array_map(fn (mixed $value): mixed => $value ?? '', $data->toArray()),
            'photo' => '',
        ];
    }

    /** The draft, as the Content Studio actions take it. Checked by validateDraft() first. */
    protected function draftData(): PersonaData
    {
        return new PersonaData(
            displayName: $this->displayName,
            username: $this->username,
            bio: $this->bio,
            shortDescription: $this->shortDescription,
            character: $this->character,
            speechStyle: $this->speechStyle,
            topics: $this->topics,
            neverWritesAbout: $this->neverWritesAbout,
            emojiFrequency: PersonaEmojiFrequency::from($this->emojiFrequency),
            engagement: PersonaEngagement::from($this->engagement),
            maxRepliesPerDay: (int) $this->maxRepliesPerDay,
        );
    }

    /**
     * Checks the draft with the actions' own rules, each message by its field,
     * every text first put as the actions store it (PersonaData::normalized())
     * — so the draft is held to the limits on what would be saved, and spaces
     * around a name, a username or a text are no reason to refuse it.
     */
    protected function validateDraft(?User $persona): void
    {
        $rules = app(PersonaValidationRules::class);

        $this->displayName = PersonaData::normalizedName($this->displayName);
        $this->username = (string) PersonaData::normalizedText($this->username);
        $this->bio = (string) PersonaData::normalizedText($this->bio);
        $this->shortDescription = (string) PersonaData::normalizedText($this->shortDescription);
        $this->character = (string) PersonaData::normalizedText($this->character);
        $this->speechStyle = (string) PersonaData::normalizedText($this->speechStyle);
        $this->topics = (string) PersonaData::normalizedText($this->topics);
        $this->neverWritesAbout = (string) PersonaData::normalizedText($this->neverWritesAbout);

        $this->validate([
            ...$rules->rules($persona, $persona !== null && $this->username === $persona->username),
            'photo' => ['nullable', File::image()->max((int) config('uploads.images.max_kilobytes', 5120))],
        ], [
            ...$rules->messages(),
            'photo.image' => 'Choose a JPEG, PNG or WebP image.',
            'photo.max' => 'Choose an image of '.number_format((int) config('uploads.images.max_kilobytes', 5120) / 1024, 0).' MB or less.',
        ]);
    }

    /**
     * Gives the Persona the chosen photo, if it is still what the editor
     * expects (`$fingerprint`, `$avatarAssetId`). A photo that cannot be used
     * leaves the rest saved and says why by the photo, so it can be chosen
     * again; a Persona changed or gone meanwhile is the page's to explain.
     *
     * @throws CannotManagePersonaException when the administrator may not, or the Persona changed or is gone
     */
    protected function savePhoto(User $persona, string $fingerprint, ?int $avatarAssetId): bool
    {
        if ($this->photo === null) {
            return true;
        }

        try {
            app(SetPersonaAvatarAction::class)->handle($this->admin(), $persona, $this->photo, $fingerprint, $avatarAssetId);
        } catch (ImageIngestException $exception) {
            report($exception);
            $this->addError('photo', 'This image can’t be used. Choose another JPEG, PNG or WebP photo.');

            return false;
        }

        $this->photo = null;

        return true;
    }

    /**
     * What the editor shows besides the draft: the levels and their meaning,
     * the limits, the photo and where the public profile is.
     *
     * @return array<string, mixed>
     */
    protected function editorViewData(?string $savedAvatarUrl): array
    {
        $username = trim($this->username);

        return [
            'emojiOptions' => collect(PersonaEmojiFrequency::cases())->mapWithKeys(fn (PersonaEmojiFrequency $level): array => [$level->value => $level->label()])->all(),
            'engagementOptions' => collect(PersonaEngagement::cases())->mapWithKeys(fn (PersonaEngagement $level): array => [$level->value => $level->label()])->all(),
            'engagementHelp' => self::ENGAGEMENT_HELP,
            'limits' => [
                'displayName' => ProfileValidationRules::DISPLAY_NAME_LIMIT,
                'username' => PersonaValidationRules::USERNAME_LIMIT,
                'bio' => ProfileValidationRules::BIO_LIMIT,
                'shortDescription' => PersonaProfile::SHORT_DESCRIPTION_LIMIT,
                'notes' => PersonaProfile::NOTES_LIMIT,
                'replies' => PersonaProfile::MOST_REPLIES_PER_DAY,
            ],
            'avatar' => [
                'url' => $this->photoPreviewUrl() ?? $savedAvatarUrl,
                'initials' => ModerationListFormat::initials($this->displayName !== '' ? $this->displayName : $username),
                'chosen' => $this->photo !== null,
            ],
            'profilePath' => $username !== '' ? '/u/'.$username : null,
        ];
    }

    protected function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function toast(string $message, string $tone = 'success'): void
    {
        $this->dispatch('rg-admin-toast', message: $message, tone: $tone);
    }

    /** The chosen photo, before it is saved; none when the browser cannot be shown it. */
    private function photoPreviewUrl(): ?string
    {
        if ($this->photo === null || $this->getErrorBag()->has('photo')) {
            return null;
        }

        try {
            return $this->photo->isPreviewable() ? $this->photo->temporaryUrl() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
