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
     * Strips from both ends everything that renders as nothing: the separators
     * `trim()` ignores (no-break space, the Unicode space range, line and
     * paragraph separators) AND the characters Unicode files under Cc and Cf —
     * NUL, NEL, the zero-width space, the zero-width joiners, the byte-order
     * mark. `\p{Cc}` and `\p{Cf}` are the property names for exactly "a
     * character with no visible rendering", which is the question this class
     * asks, so they are used instead of a list of codepoints somebody has to keep
     * extending.
     *
     * NUL and U+200B are the two that mattered. NUL passed this normalizer and
     * was then removed by the plain `trim()` in CreateModerationLogAction, which
     * DOES include "\0" in its default character list — so an irreversible
     * finalization was recorded with reason NULL. U+200B is in no whitespace
     * property at all, so a reason made of one was stored and displayed as blank
     * forever. Both are now empty here, before anything irreversible happens, and
     * there is one definition of empty rather than two that disagree.
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
        return preg_replace('/^[\s\p{Z}\p{Cc}\p{Cf}]+|[\s\p{Z}\p{Cc}\p{Cf}]+$/u', '', $reason)
            ?? $reason;
    }
}
