<?php

namespace App\Support\Database;

final class LikePattern
{
    public const string ESCAPE_CHARACTER = '!';

    public static function containing(string $value): string
    {
        $escaped = str_replace(
            [self::ESCAPE_CHARACTER, '%', '_'],
            [self::ESCAPE_CHARACTER.self::ESCAPE_CHARACTER, self::ESCAPE_CHARACTER.'%', self::ESCAPE_CHARACTER.'_'],
            $value,
        );

        return "%{$escaped}%";
    }
}
