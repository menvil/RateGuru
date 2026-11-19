<?php

namespace App\Support\Games\Play;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * A test link to one published version of a game: the game's public page
 * with `v`, signed and good for seven days, which an administrator copies
 * from the game's Preview. Whoever holds it plays that version and nothing
 * is recorded — no assignment, visit, arrival, attempt, event or conversion
 * (docs/architecture/games.md, "Test links"). An address whose `v` carries
 * no valid signature — none at all, one tampered with, or one past its
 * seven days — is the game's ordinary page.
 *
 * Only `v` and `expires` are signed: a parameter added to the link on its
 * way — `fbclid` from a Facebook message, `utm_*` from a mail tool — leaves
 * it working, as none of them changes which version plays or for how long.
 * A parameter is told by the name PHP reads it under, so any other spelling
 * of a signed one — `%76`, ` v`, `v[]` — counts as signed: it would replace
 * the signed value, so it breaks the signature instead.
 */
final class VersionTestLink
{
    public const int DAYS = 7;

    /** The parameters the signature covers; any other in the address is ignored. */
    private const array SIGNED = ['v', 'expires'];

    public static function url(string $slug, int $versionId): string
    {
        return URL::temporarySignedRoute('games.play', now()->addDays(self::DAYS), ['slug' => $slug, 'v' => $versionId]);
    }

    /** The version a request names through a valid test link; null for any other request. */
    public static function versionId(Request $request): ?int
    {
        $version = $request->query('v');

        if (! is_string($version) || preg_match('/\A[1-9][0-9]{0,17}\z/', $version) !== 1
            || ! $request->hasValidSignatureWhileIgnoring(self::unsigned(...))) {
            return null;
        }

        return (int) $version;
    }

    /** Whether a parameter of the raw query string is left out of the signature: none PHP reads as a signed one. */
    private static function unsigned(string $parameter): bool
    {
        parse_str($parameter.'=', $parsed);

        return array_intersect(array_keys($parsed), self::SIGNED) === [];
    }
}
