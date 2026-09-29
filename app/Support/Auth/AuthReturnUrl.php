<?php

namespace App\Support\Auth;

/**
 * Decides whether a "return here after authentication" value may be
 * followed. The value always arrives from the browser — a hidden form field
 * or a query parameter — so it is a claim, never a destination: only a path
 * on this application survives, and everything else becomes the fallback.
 *
 * This is the single guard against an open redirect in the authentication
 * flow; nothing redirects to a client-supplied location without passing
 * through it.
 */
final class AuthReturnUrl
{
    /** Where an unusable value sends the person instead. */
    public const string FALLBACK = '/';

    private const int MAX_LENGTH = 2048;

    /** The path itself when it is safe to follow, null when it is not. */
    public static function sanitize(mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '' || strlen($candidate) > self::MAX_LENGTH) {
            return null;
        }

        // A local path starts with exactly one slash. Two slashes are a
        // protocol-relative URL, and anything else is either relative to an
        // unknown base or carries a scheme (https:, javascript:, data:).
        if ($candidate[0] !== '/' || str_starts_with($candidate, '//')) {
            return null;
        }

        // Browsers read a backslash as a forward slash, which would turn
        // "/\evil.example" into a protocol-relative URL after all.
        if (str_contains($candidate, '\\')) {
            return null;
        }

        // Control characters, whitespace and DEL never belong in a URL; they
        // are how header injection and parser confusion are attempted.
        if (preg_match('/[\x00-\x20\x7F]/', $candidate) === 1) {
            return null;
        }

        $parts = parse_url($candidate);

        if ($parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
        ) {
            return null;
        }

        return $candidate;
    }

    public static function resolve(mixed $candidate, string $fallback = self::FALLBACK): string
    {
        return self::sanitize($candidate) ?? $fallback;
    }
}
