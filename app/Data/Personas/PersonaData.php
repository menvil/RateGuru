<?php

namespace App\Data\Personas;

use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use App\Models\PersonaProfile;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Everything Content Studio may set on a Persona, and nothing more: its public
 * display name, username and bio, and what Content Studio keeps about it for
 * itself. There is no room here for a role, a status, trust, an address, a
 * password or a sign-in identity — those are the server's to set, or the
 * moderation actions' to change, never an editor's.
 *
 * A null username asks CreatePersonaAction to make one from the display name.
 */
final readonly class PersonaData
{
    public function __construct(
        public string $displayName,
        public ?string $username = null,
        public ?string $bio = null,
        public ?string $shortDescription = null,
        public ?string $character = null,
        public ?string $speechStyle = null,
        public ?string $topics = null,
        public ?string $neverWritesAbout = null,
        public PersonaEmojiFrequency $emojiFrequency = PersonaEmojiFrequency::None,
        public PersonaEngagement $engagement = PersonaEngagement::Off,
        public int $maxRepliesPerDay = 0,
    ) {}

    /**
     * A Persona as it is stored. A Persona made before it had a profile reads
     * as one with an empty profile, as its first save would leave it.
     */
    public static function of(User $persona, ?PersonaProfile $profile): self
    {
        $profile ??= new PersonaProfile;

        return new self(
            displayName: (string) ($persona->display_name ?: $persona->name),
            username: $persona->username,
            bio: $persona->bio,
            shortDescription: $profile->short_description,
            character: $profile->character,
            speechStyle: $profile->speech_style,
            topics: $profile->topics,
            neverWritesAbout: $profile->never_writes_about,
            emojiFrequency: $profile->emoji_frequency,
            engagement: $profile->engagement,
            maxRepliesPerDay: (int) $profile->max_replies_per_day,
        );
    }

    /**
     * As it is stored: the display name on one line, the username and the
     * texts trimmed, and an empty text as no text at all.
     */
    public function normalized(): self
    {
        return new self(
            displayName: Str::squish($this->displayName),
            username: self::text($this->username),
            bio: self::text($this->bio),
            shortDescription: self::text($this->shortDescription),
            character: self::text($this->character),
            speechStyle: self::text($this->speechStyle),
            topics: self::text($this->topics),
            neverWritesAbout: self::text($this->neverWritesAbout),
            emojiFrequency: $this->emojiFrequency,
            engagement: $this->engagement,
            maxRepliesPerDay: $this->maxRepliesPerDay,
        );
    }

    /**
     * Keyed as the editor's fields and its validation are.
     *
     * @return array{displayName: string, username: ?string, bio: ?string, shortDescription: ?string, character: ?string, speechStyle: ?string, topics: ?string, neverWritesAbout: ?string, emojiFrequency: string, engagement: string, maxRepliesPerDay: int}
     */
    public function toArray(): array
    {
        return [
            'displayName' => $this->displayName,
            'username' => $this->username,
            'bio' => $this->bio,
            'shortDescription' => $this->shortDescription,
            'character' => $this->character,
            'speechStyle' => $this->speechStyle,
            'topics' => $this->topics,
            'neverWritesAbout' => $this->neverWritesAbout,
            'emojiFrequency' => $this->emojiFrequency->value,
            'engagement' => $this->engagement->value,
            'maxRepliesPerDay' => $this->maxRepliesPerDay,
        ];
    }

    /**
     * What an editor was drawn from, in one value: a save holds it up to the
     * Persona as stored now, so a draft made before somebody else's save is
     * refused instead of quietly writing over it.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->normalized()->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * The public half, as `users` holds it. The name and the display name are
     * one and the same for a Persona.
     *
     * @return array{name: string, display_name: string, bio: ?string}
     */
    public function accountAttributes(): array
    {
        return [
            'name' => $this->displayName,
            'display_name' => $this->displayName,
            'bio' => $this->bio,
        ];
    }

    /**
     * The internal half, as `persona_profiles` holds it.
     *
     * @return array{short_description: ?string, character: ?string, speech_style: ?string, topics: ?string, never_writes_about: ?string, emoji_frequency: PersonaEmojiFrequency, engagement: PersonaEngagement, max_replies_per_day: int}
     */
    public function profileAttributes(): array
    {
        return [
            'short_description' => $this->shortDescription,
            'character' => $this->character,
            'speech_style' => $this->speechStyle,
            'topics' => $this->topics,
            'never_writes_about' => $this->neverWritesAbout,
            'emoji_frequency' => $this->emojiFrequency,
            'engagement' => $this->engagement,
            'max_replies_per_day' => $this->maxRepliesPerDay,
        ];
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
