<?php

namespace App\Support\Configuration;

/**
 * A number from the environment for a config file. A key present with an
 * empty value is this repository's convention for "not configured for this
 * target" — what .env.example and the environment templates ship — so blank,
 * like anything that is no number, keeps the default, where `(int) env()`
 * would make it 0: a queue job taken again at once, a session that ends at
 * once, an upload limit of nothing.
 */
final class EnvironmentValue
{
    /** @param  mixed  $value  what `env()` returned for the key, read in the config file */
    public static function integer(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
