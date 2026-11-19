<?php

namespace App\Services\Traffic\Connectors;

use App\Enums\TrafficConnector;

/**
 * The connector for a network, and whether it has been checked on a real
 * account (config/traffic_presets.php, "connectors"): an unverified one may
 * read, never block by itself.
 */
final class ConnectorRegistry
{
    public function connector(TrafficConnector $connector): TrafficNetworkConnector
    {
        return match ($connector) {
            TrafficConnector::PopAds => app(PopAdsConnector::class),
            TrafficConnector::ExoClick => app(ExoClickConnector::class),
        };
    }

    public function verified(TrafficConnector $connector): bool
    {
        return (bool) config('traffic_presets.connectors.'.$connector->value.'.verified', false);
    }
}
