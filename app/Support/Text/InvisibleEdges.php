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
        return preg_replace('/^[\s\p{Z}\p{Cc}\p{Cf}]+|[\s\p{Z}\p{Cc}\p{Cf}]+$/u', '', $text)
            ?? $text;
    }
}
