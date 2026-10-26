<?php

namespace App\Enums;

/**
 * The tone post text suggestions, or a discussion, are written in, chosen
 * before they are asked for. Each tone is a fixed instruction of the
 * application's own: nothing typed by anyone becomes part of what the model
 * is told.
 *
 * Spicy is adult humour, a mood an administrator chooses and never the
 * default. It is a tone of voice, never a fact about anyone's age: the rules
 * about the people shown hold in it exactly as in every other tone.
 */
enum StudioPostTextTone: string
{
    case Playful = 'playful';
    case Humour = 'humour';
    case Cheeky = 'cheeky';
    case Spicy = 'spicy';
    case Irony = 'irony';
    case Neutral = 'neutral';

    /** The tone the editor offers first: the site's own. */
    public const DEFAULT = self::Cheeky;

    public function label(): string
    {
        return match ($this) {
            self::Playful => 'Playful',
            self::Humour => 'Humour',
            self::Cheeky => 'Cheeky',
            self::Spicy => 'Spicy / Adult humour',
            self::Irony => 'Irony',
            self::Neutral => 'Neutral',
        };
    }

    /** What the tone means, as the editor says it. */
    public function hint(): string
    {
        return match ($this) {
            self::Playful => 'Light and playful.',
            self::Humour => 'Openly funny.',
            self::Cheeky => 'Bold, with double meanings — never explicit.',
            self::Spicy => 'Grown-up, flirty and saucy — clever innuendo, never graphic.',
            self::Irony => 'Dry, ironic, deadpan.',
            self::Neutral => 'Plain editorial.',
        };
    }

    /** How the model is to write in this tone. */
    public function instruction(): string
    {
        return match ($this) {
            self::Playful => 'Playful: light, warm and cheerful. Gentle wordplay and a friendly wink; no sarcasm, no innuendo.',
            self::Humour => 'Humour: openly funny. Punchlines, comic exaggeration and absurd observations about what is visible; aim for a laugh.',
            self::Cheeky => 'Cheeky: bold and saucy, with flirtatious double meanings and innuendo where the photo invites them — suggestive, never explicit, and never about anyone who may be a minor.',
            self::Spicy => 'Spicy (adult humour): grown-up, playful and flirtatious, in a relaxed, conversational voice — saucy innuendo, clever double meanings, teasing, sarcasm and self-irony; the boldest of the tones. It sets the mood of the text and says nothing about anyone\'s age: the rules about the people shown hold exactly as in every other tone, so its innuendo plays on the situation, the post and wordplay, and on the commenters\' own banter. Suggestive, never graphic: no description of sexual acts, and nothing that humiliates or degrades anyone.',
            self::Irony => 'Irony: dry irony and deadpan sarcasm. Understatement, mock seriousness and knowing remarks, aimed at the situation, never at a person.',
            self::Neutral => 'Neutral: plain editorial. Clear, descriptive and engaging; no jokes, no innuendo, no sarcasm.',
        };
    }
}
