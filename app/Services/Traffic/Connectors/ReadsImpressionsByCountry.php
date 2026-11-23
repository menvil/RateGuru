<?php

namespace App\Services\Traffic\Connectors;

/** A network whose report can say which country its impressions were shown in. */
interface ReadsImpressionsByCountry
{
    /**
     * @param  list<string>  $campaignIds  the network's IDs of the campaigns RateGuru saw clicks of
     * @return list<array{campaign: string, site: string, country: string, impressions: int, amount: string}>
     *
     * @throws ConnectorException
     */
    public function impressionsByCountry(ConnectorSession $session, string $networkDay, array $campaignIds): array;
}
