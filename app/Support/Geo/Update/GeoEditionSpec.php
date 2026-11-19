<?php

namespace App\Support\Geo\Update;

use App\Enums\GeoEdition;

/** One edition's entry of config/geo.php: what to fetch and how to check it. */
final readonly class GeoEditionSpec
{
    /**
     * @param  list<string>  $databaseTypes
     * @param  list<string>  $sources
     */
    public function __construct(
        public GeoEdition $edition,
        public string $package,
        public string $file,
        public array $databaseTypes,
        public int $maxArchiveBytes,
        public int $minDatabaseBytes,
        public int $maxDatabaseBytes,
        public ?int $maxBuildAgeDays,
        public int $staleAfterDays,
        public array $sources,
    ) {}

    /** @param  array<string, mixed>  $config */
    public static function fromConfig(GeoEdition $edition, array $config): self
    {
        return new self(
            edition: $edition,
            package: (string) $config['package'],
            file: (string) $config['file'],
            databaseTypes: array_values(array_map('strval', (array) $config['database_types'])),
            maxArchiveBytes: (int) $config['max_archive_bytes'],
            minDatabaseBytes: (int) $config['min_database_bytes'],
            maxDatabaseBytes: (int) $config['max_database_bytes'],
            maxBuildAgeDays: isset($config['max_build_age_days']) ? (int) $config['max_build_age_days'] : null,
            staleAfterDays: (int) $config['stale_after_days'],
            sources: array_values(array_map('strval', (array) $config['sources'])),
        );
    }
}
