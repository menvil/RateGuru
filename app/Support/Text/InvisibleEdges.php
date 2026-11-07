<?php

namespace App\Support\Text;

/**
 * Text without whatever renders as nothing at either end — what `trim()` is
 * usually taken to do. Its default list is ASCII only, so a no-break space,
 * a zero-width space or a byte-order mark survives it, and text made only of
 * those passes an "is it empty" check while showing nothing.
 *
 * Strips the separators (`\s`, `\p{Z}`) and the characters Unicode files as
 * having no visible rendering (`\p{Cc}`, `\p{Cf}`). Invalid UTF-8 comes back
 * unchanged rather than empty, so whatever rejects malformed text rejects it
 * for what it is instead of reporting it missing.
 */
final class InvisibleEdges
{
    public static function strip(string $text): string
    {
        // The tag characters (U+E0020–U+E007F) are Cf too, but at the end they
        // are part of an emoji — a subdivision flag such as Scotland's — so the
        // end keeps them; text made only of them still comes back empty.
        return preg_replace('/^[\s\p{Z}\p{Cc}\p{Cf}]+|(?:(?![\x{E0020}-\x{E007F}])[\s\p{Z}\p{Cc}\p{Cf}])+$/u', '', $text)
            ?? $text;
    }
}
