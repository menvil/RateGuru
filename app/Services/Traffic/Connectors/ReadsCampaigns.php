<?php

namespace App\Services\Traffic\Connectors;

interface ReadsCampaigns
{
    /**
     * @param  list<string>  $campaignIds
     * @return list<NetworkCampaign>
     *
     * @throws ConnectorException
     */
    public function campaigns(ConnectorSession $session, array $campaignIds): array;
}
