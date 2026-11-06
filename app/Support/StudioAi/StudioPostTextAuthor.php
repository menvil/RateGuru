<?php

namespace App\Support\StudioAi;

use App\Enums\PersonaEmojiFrequency;
use App\Models\User;

/**
 * The voice a post's titles and descriptions are written in: the Persona the
 * post is published as, as the model is told about it — its display name
 * and the parts of its PersonaProfile that say how it writes: its short
 * description, character, speech style, topics, what it never writes about
 * and how often it uses emoji. Nothing else of the account: no email,
 * username, id, password, sign-in or security setting, and not its
 * engagement or daily reply limit.
 *
 * A post has a voice only while its author is a Persona that is not
 * deleted; with none, its texts are written as the site's own.
 */
final readonly class StudioPostTextAuthor
{
    private function __construct(
        public int $userId,
        public string $name,
        public ?string $shortDescription,
        public ?string $character,
        public ?string $speechStyle,
        public ?string $topics,
        public ?string $neverWritesAbout,
        public PersonaEmojiFrequency $emojiFrequency,
    ) {}

    /** The voice of this author — loaded with its profile — as it is now; none for no author, or one that is no Persona's to lend. */
    public static function of(?User $author): ?self
    {
        if ($author === null || ! $author->isPersona() || $author->isTombstoned()) {
            return null;
        }

        $profile = $author->personaProfile;

        return new self(
            userId: (int) $author->getKey(),
            name: StudioDiscussionPersona::nameOf($author),
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
     * What a batch keeps a hash of, to tell whether the author changed since:
     * everything the model was told, with whose it is.
     *
     * @return list<int|string|null>
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

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
