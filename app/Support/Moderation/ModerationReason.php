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
     * paragraph separators, the zero-width no-break space, and the two
     * whitespace characters Unicode files under Cc/Cf rather than Z — NEL
     * (U+0085) and the Mongolian vowel separator (U+180E).
     *
     * Those last two are named explicitly even though PHP's `u` modifier also
     * turns on PCRE2_UCP, which already makes `\s` match the full White_Space
     * property. That coupling is real but implicit, and a reason made of one NEL
     * is exactly the empty audit record this class exists to refuse — so it does
     * not rest on a flag nobody writing here would think to check.
     *
     * Invalid UTF-8 is returned UNCHANGED rather than normalized. preg_replace
     * fails on it and returns null, and casting that to a string would hand the
     * callers an empty reason — so an administrator who typed a perfectly real
     * reason containing one bad byte would be told a reason is required, with
     * what they wrote silently discarded. Returning the input keeps the failure
     * honest: the text is still there, and whatever rejects malformed text
     * rejects it for what it is.
     */
    public static function normalize(string $reason): string
    {
        return preg_replace('/^[\s\p{Z}\x{85}\x{180E}\x{FEFF}]+|[\s\p{Z}\x{85}\x{180E}\x{FEFF}]+$/u', '', $reason)
            ?? $reason;
    }
}
