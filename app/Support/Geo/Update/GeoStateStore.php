<?php

namespace App\Support\Geo\Update;

use App\Enums\GeoEdition;
use App\Support\Geo\GeoDatabaseLocator;

/**
 * The facts about an edition's file, kept in a JSON file beside it — not in
 * the database: the files live outside the backup, and a table restored from
 * a backup would describe a file that is not there. Only the update command
 * writes it, through a temporary file renamed into place.
 */
final readonly class GeoStateStore
{
    public function __construct(private GeoDatabaseLocator $locator) {}

    /** @return array<string, mixed> */
    public function read(GeoEdition $edition): array
    {
        $path = $this->locator->state($edition);

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array<string, mixed>  $state */
    public function write(GeoEdition $edition, array $state): void
    {
        $path = $this->locator->state($edition);
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        @chmod($temporary, 0640);
        rename($temporary, $path);
    }
}
