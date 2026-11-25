<?php

namespace App\Support\Languages;

/**
 * What of a text tells its language: its words, without links, @handles and #tags, digits, emoji and the words
 * people write the same way in many languages ("ok", "wow", "selfie"). An empty result means the text tells none.
 */
final class TextCleaner
{
    /** Words that say nothing about the language they were written in. */
    public const NEUTRAL_WORDS = [
        'ok', 'okay', 'oki', 'lol', 'lmao', 'omg', 'wow', 'woow', 'wooow', 'top', 'super', 'cool', 'xd', 'haha',
        'hahaha', 'ahah', 'ahaha', 'hehe', 'xoxo', 'bravo', 'mega', 'wtf', 'yes', 'no', 'ciao', 'hi', 'hey', 'like',
        'insta', 'selfie', 'photo', 'foto', 'love', 'nice', 'sexy', 'hot', 'beauty', 'perfect', 'wau', 'vau',
    ];

    /** Below this many letters a text's detected language counts for less, in proportion. */
    public const FULL_WEIGHT_LETTERS = 12;

    public static function clean(string $text): string
    {
        $text = preg_replace('~https?://\S+|www\.\S+|\S+@\S+\.\S+|[@#][\p{L}\p{M}\p{N}_.]+~iu', ' ', $text) ?? '';

        if (preg_match_all("~[\p{L}\p{M}]+(?:['’-][\p{L}\p{M}]+)*~u", $text, $matches) === false) {
            return '';
        }

        $neutral = array_flip(self::NEUTRAL_WORDS);
        $words = array_filter($matches[0], fn (string $word): bool => ! isset($neutral[mb_strtolower($word)]));

        return implode(' ', $words);
    }

    public static function letters(string $text): int
    {
        return preg_match_all('~\p{L}~u', $text) ?: 0;
    }

    /**
     * How much a detected language counts for a cleaned text, 0 to 1: in full from FULL_WEIGHT_LETTERS letters,
     * less for fewer — a one-word comment is easily mistaken for a close language.
     */
    public static function weight(string $cleaned): float
    {
        return min(1.0, self::letters($cleaned) / self::FULL_WEIGHT_LETTERS);
    }
}
