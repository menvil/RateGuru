<?php

namespace App\Support\Locale;

use Illuminate\Support\HtmlString;

/**
 * A line of the interface with text people wrote put into it — a post's title,
 * the site's name, a player's @name, a search — each such piece in a <bdi> of
 * its own. The line keeps the page's direction and every piece reads its own
 * way inside it, so an English title ending in "?" keeps the question mark at
 * its end in an Arabic sentence, as an Arabic title does in an English one.
 *
 * Everything is escaped: the line, what is put into it and the other
 * replacements alike.
 */
final class IsolatedTranslation
{
    /**
     * @param  array<string, string>  $isolated  replacements people wrote, each read its own way
     * @param  array<string, string|int|float>  $replace  the line's other replacements, put in as they are
     */
    public static function line(string $key, array $isolated, array $replace = []): HtmlString
    {
        $pieces = [];

        foreach (array_keys($isolated) as $index => $name) {
            // A stand-in the translator passes through untouched (no case to
            // change, nothing to escape), swapped for the piece once the line
            // is escaped.
            $marker = "\u{E000}{$index}\u{E001}";
            $pieces[$marker] = '<bdi>'.e($isolated[$name]).'</bdi>';
            $replace[$name] = $marker;
        }

        return new HtmlString(strtr(e(__($key, $replace)), $pieces));
    }
}
