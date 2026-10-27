<?php

namespace App\Support\StudioAi;

use App\Enums\PersonaEmojiFrequency;
use App\Models\User;

/**
 * One Persona taking part in a discussion generation, as the model is told
 * about it: an alias of the request's own (`persona_1`), its display name,
 * and the parts of its PersonaProfile that say how it writes — its short
 * description, character, speech style, topics, what it never writes about
 * and how often it uses emoji. Nothing else of the account: no email,
 * username, id, password, sign-in or security setting, and not its
 * engagement or daily reply limit, which govern replies it would write on
 * its own and play no part in a discussion an administrator prepares.
 */
final readonly class StudioDiscussionPersona
{
    private function __construct(
        public string $alias,
        public int $userId,
        public string $name,
        public ?string $shortDescription,
        public ?string $character,
        public ?string $speechStyle,
        public ?string $topics,
        public ?string $neverWritesAbout,
        public PersonaEmojiFrequency $emojiFrequency,
    ) {}

    /** The Persona as this account and its profile — loaded with it — have it now. */
    public static function of(string $alias, User $persona): self
    {
        $profile = $persona->personaProfile;

        return new self(
            alias: $alias,
            userId: (int) $persona->getKey(),
            name: self::nameOf($persona),
            shortDescription: self::text($profile?->short_description),
            character: self::text($profile?->character),
            speechStyle: self::text($profile?->speech_style),
            topics: self::text($profile?->topics),
            neverWritesAbout: self::text($profile?->never_writes_about),
            emojiFrequency: $profile->emoji_frequency ?? PersonaEmojiFrequency::None,
        );
    }

    /**
     * As the model reads it: data, never instructions.
     *
     * @return array<string, string|null>
     */
    public function toPrompt(): array
    {
        return [
            'persona' => $this->alias,
            'name' => $this->name,
            'short_description' => $this->shortDescription,
            'character' => $this->character,
            'speech_style' => $this->speechStyle,
            'topics_and_jokes' => $this->topics,
            'never_writes_about' => $this->neverWritesAbout,
            'emoji' => $this->emojiFrequency->inWords(),
        ];
    }

    /**
     * What a generation keeps a hash of, to tell whether the Persona changed
     * since: everything the model was told, with whose it is.
     *
     * @return array<int|string|null>
     */
    public function fingerprint(): array
    {
        return [
            $this->userId,
            $this->name,
            $this->shortDescription,
            $this->character,
            $this->speechStyle,
            $this->topics,
            $this->neverWritesAbout,
            $this->emojiFrequency->value,
        ];
    }

    /**
     * The name an account goes by in a request — the same in every locale,
     * so what a generation keeps a hash of never changes with the language of
     * whoever asks again: a deleted account's is a fixed one.
     */
    public static function nameOf(User $user): string
    {
        if ($user->isTombstoned()) {
            return 'A deleted account';
        }

        return (string) ($user->display_name ?: ($user->name ?: $user->username));
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
