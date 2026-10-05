<?php

namespace App\Support\Moderation;

/**
 * The one place a moderation reason is reduced to "is there actually a reason
 * here". It exists because `trim()` is not that test: its default character
 * list is ASCII only, so a reason consisting of a single non-breaking space —
 * U+00A0, which a paste from a word processor or a chat client produces
 * routinely — survives it and satisfies an `=== ''` check while telling a
 * future reader nothing at all. An irreversible removal is recorded in the
 * moderation log forever; the reason is the whole audit value of that record.
 *
 * Both finalize actions normalize through here rather than keeping a copy
 * each, so the two can never disagree about what an empty reason is.
 */
final class ModerationReason
{
    /**
     * Strips Unicode whitespace from both ends, including the separators
     * `trim()` ignores: no-break space, the Unicode space range, line and
     * paragraph separators, and the zero-width no-break space.
     */
    public static function normalize(string $reason): string
    {
        return (string) preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', $reason);
    }
}
