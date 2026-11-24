<?php

namespace App\Support\Locale;

use Illuminate\Support\HtmlString;

/**
 * The direction of an element that holds text people wrote — the site's users
 * or its administrators: dir="auto", so the text's own first letters decide
 * which way it reads and where it lines up, whatever the page's direction.
 *
 * A text with no letter at all — only digits, emoji or punctuation, such as
 * "😂😂" or "10/10!" — gets nothing and keeps the page's direction: a browser
 * lays such text out left to right under dir="auto", which would put it at the
 * far left of a page read right to left.
 */
final class WrittenText
{
    public static function dir(?string $text): HtmlString
    {
        return new HtmlString(self::hasLetter((string) $text) ? 'dir="auto"' : '');
    }

    public static function hasLetter(string $text): bool
    {
        return preg_match('/\p{L}/u', $text) === 1;
    }
}
