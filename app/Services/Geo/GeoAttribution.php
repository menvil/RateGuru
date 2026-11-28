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

    public const string DBIP_NETWORK_CREDIT = 'IP to ASN data by DB-IP';

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
     * The networks' database and lists, as Tracking shows them: the build of
     * DB-IP ASN Lite installed, when it was last checked and its last error,
     * how many networks the hosting list names and how many ranges Private
     * Relay's list holds — null while one is missing — each list's last
     * error, and DB-IP's credit, which the networks' database asks for
     * whichever city database serves.
     *
     * @return array{installed: mixed, checked: mixed, error: mixed, hosting: int|null, hostingError: string|null, relay: int|null, relayError: string|null, credit: array{text: string, url: string}}
     */
    public function networks(): array
    {
        // The state whether or not a build serves: a first install that keeps failing shows its error.
        $state = $this->states->read(GeoEdition::DbipAsnLite);
        $list = function (string $name): array {
            $path = $this->locator->networkState($name);
            $state = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
            $state = is_array($state) ? $state : [];

            return [
                is_file($this->locator->networkList($name)) && is_int($state['installed']['entries'] ?? null) ? $state['installed']['entries'] : null,
                is_string($state['last_error']['code'] ?? null) ? $state['last_error']['code'] : null,
            ];
        };
        [$hosting, $hostingError] = $list('hosting');
        [$relay, $relayError] = $list('relay');

        return [
            'installed' => is_file($this->locator->current(GeoEdition::DbipAsnLite)) ? ($state['installed'] ?? null) : null,
            'checked' => $state['last_checked_at'] ?? null,
            'error' => $state['last_error']['code'] ?? null,
            'hosting' => $hosting,
            'hostingError' => $hostingError,
            'relay' => $relay,
            'relayError' => $relayError,
            'credit' => ['text' => self::DBIP_NETWORK_CREDIT, 'url' => self::DBIP_URL],
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
