<?php

namespace App\Services\Geo;

use App\Enums\GeoEdition;
use App\Support\Geo\GeoDatabaseLocator;
use App\Support\Geo\Update\GeoStateStore;

/**
 * Which IP database places the visitors, and how its licence asks to be
 * credited wherever their countries and cities are shown: the edition the
 * administrator chose, the one serving — the chosen one, else the other
 * standing in, else none yet — what the last update of the chosen one left
 * in its state file (the build installed, when it was last checked, its
 * last error), and the credit of the edition serving: DB-IP's as a link,
 * MaxMind's as its sentence. Reads the files under storage/geoip, never the
 * database.
 */
final readonly class GeoAttribution
{
    public const string MAXMIND_CREDIT = 'This product includes GeoLite Data created by MaxMind, available from https://www.maxmind.com.';

    public const string DBIP_CREDIT = 'IP Geolocation by DB-IP';

    public const string DBIP_URL = 'https://db-ip.com';

    public function __construct(
        private GeoDatabaseLocator $locator,
        private GeoStateStore $states,
    ) {}

    /** @return array{chosen: GeoEdition, serving: GeoEdition|null, installed: mixed, checked: mixed, error: mixed, credit: array{text: string, url: string|null}} */
    public function read(GeoEdition $chosen): array
    {
        $serving = $this->locator->serving($chosen);
        $state = $this->states->read($chosen);

        return [
            'chosen' => $chosen,
            'serving' => $serving,
            'installed' => $state['installed'] ?? null,
            'checked' => $state['last_checked_at'] ?? null,
            'error' => $state['last_error']['code'] ?? null,
            'credit' => self::credit($serving),
        ];
    }

    /**
     * The credit an edition's licence asks for: DB-IP's names it with a link
     * to it; MaxMind's — the default edition, and what stands while nothing
     * serves yet — is a sentence of its own.
     *
     * @return array{text: string, url: string|null}
     */
    public static function credit(?GeoEdition $serving): array
    {
        return $serving === GeoEdition::DbipCityLite
            ? ['text' => self::DBIP_CREDIT, 'url' => self::DBIP_URL]
            : ['text' => self::MAXMIND_CREDIT, 'url' => null];
    }
}
