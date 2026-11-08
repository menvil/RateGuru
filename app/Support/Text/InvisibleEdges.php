<?php

namespace App\Support\Text;

/**
 * Text without whatever renders as nothing at either end — what `trim()` is
 * usually taken to do. Its default list is ASCII only, so a no-break space,
 * a zero-width space or a byte-order mark survives it, and text made only of
 * those passes an "is it empty" check while showing nothing.
 *
 * Strips the separators (`\s`, `\p{Z}`) and the characters Unicode files as
 * having no visible rendering (`\p{Cc}`, `\p{Cf}`), and text made only of
 * those comes back empty. Two kinds are kept at the edges of text that shows
 * something: the marks that set which way it runs, and the tag characters
 * that end an emoji. Invalid UTF-8 comes back unchanged rather than empty,
 * so whatever rejects malformed text rejects it for what it is instead of
 * reporting it missing.
 */
final class InvisibleEdges
{
    private const INVISIBLE = '[\s\p{Z}\p{Cc}\p{Cf}]';

    /**
     * Left-to-right, right-to-left and Arabic letter marks: invisible, but in
     * right-to-left text the side its last punctuation sits on depends on one.
     */
    private const DIRECTION_MARK = '[\x{200E}\x{200F}\x{061C}]';

    /** The tag characters, U+E0020–U+E007F, the last of them the cancel tag. */
    private const TAG = '[\x{E0020}-\x{E007F}]';

    public static function strip(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            return $text;
        }

        if (preg_match('/^'.self::INVISIBLE.'*$/u', $text) === 1) {
            return '';
        }

        // Of the invisible run at the start, only its direction marks stay.
        $text = (string) preg_replace_callback('/^'.self::INVISIBLE.'+/u', self::marksOf(...), $text);

        // So of the one at the end. Tag characters there are kept only as the
        // tail of an emoji, a subdivision flag such as Scotland's: the black
        // flag, its tags, the cancel tag. Any other run of them shows nothing
        // and goes, with whatever invisible was around it.
        do {
            $before = $text;
            $text = (string) preg_replace_callback('/(?:(?!'.self::TAG.')'.self::INVISIBLE.')+$/u', self::marksOf(...), $text);

            if (preg_match('/\x{1F3F4}[\x{E0020}-\x{E007E}]+\x{E007F}(?='.self::DIRECTION_MARK.'*$)/u', $text) !== 1) {
                $text = (string) preg_replace('/'.self::TAG.'+(?='.self::DIRECTION_MARK.'*$)/u', '', $text);
            }
        } while ($text !== $before);

        return $text;
    }

    /** @param  array<int, string>  $run */
    private static function marksOf(array $run): string
    {
        return (string) preg_replace('/(?!'.self::DIRECTION_MARK.')./su', '', $run[0]);
    }
}
