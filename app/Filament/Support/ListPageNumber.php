<?php

namespace App\Filament\Support;

/**
 * A list's page number as the address or a request gives it: a whole number
 * from 1 to MOST, and the first page for anything else.
 *
 * A number past what an integer holds, or an array, broke the page with an
 * error instead; one merely huge reached the query as an enormous offset.
 */
final class ListPageNumber
{
    /** Far beyond any list in the panel. */
    public const MOST = 100_000;

    public static function from(mixed $value): int
    {
        $number = match (true) {
            is_int($value) => $value,
            is_string($value) && ctype_digit($value) && strlen($value) <= strlen((string) self::MOST) => (int) $value,
            default => 0,
        };

        return $number >= 1 && $number <= self::MOST ? $number : 1;
    }
}
